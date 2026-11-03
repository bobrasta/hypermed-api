<?php

namespace Hypermed\Messaging\Services;

use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachmentService
{
    /**
     * Store an upload (and an image thumbnail) on the configured disk.
     *
     * @return array{disk: string, path: string, mime: string, size: int, original_name: string, thumb_path: ?string}
     */
    public function store(UploadedFile $file, int $conversationId): array
    {
        $disk = (string) config('messaging.attachments.disk', 'local');
        $originalName = $this->cleanName($file->getClientOriginalName());
        $ext = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        if (in_array($ext, (array) config('messaging.attachments.blocked_extensions', []), true)) {
            throw MessagingException::validation(['file' => ['This file type is not allowed.']]);
        }

        // Server-side detection; never trust the client's Content-Type.
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (in_array($mime, ['image/svg+xml', 'text/html', 'application/x-httpd-php'], true)) {
            $mime = 'application/octet-stream';
        }

        $dir = trim((string) config('messaging.attachments.directory', 'messaging'), '/')
            .'/'.$conversationId.'/'.now()->format('Y/m');
        $basename = (string) Str::uuid();
        $filename = $basename.($ext !== '' ? '.'.preg_replace('/[^a-z0-9]/', '', $ext) : '');

        $path = Storage::disk($disk)->putFileAs($dir, $file, $filename, ['visibility' => 'private']);
        if ($path === false) {
            throw new \RuntimeException('Could not store attachment.');
        }

        $thumbPath = null;
        if (str_starts_with($mime, 'image/')) {
            $thumbPath = $this->makeThumbnail($file->getRealPath(), $disk, $dir.'/'.$basename.'_thumb');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'original_name' => $originalName,
            'thumb_path' => $thumbPath,
        ];
    }

    /**
     * Absolute, short-lived signed URL. Expiry is rounded to 5-minute buckets so
     * the same URL is reused for a while (client image caches stay effective);
     * validity is always at least TTL - 5 minutes.
     */
    public function signedUrl(Attachment $attachment, bool $thumb = false): string
    {
        $ttl = max(6, (int) config('messaging.attachments.signed_url_ttl', 10)) * 60;
        $bucket = 300;
        $expires = (intdiv(time(), $bucket) * $bucket) + $ttl;

        $params = ['attachment' => $attachment->id];
        if ($thumb) {
            $params['thumb'] = 1;
        }

        // Relative signature: survives reverse proxies that rewrite host/scheme.
        $relative = URL::temporarySignedRoute('messaging.attachments.download', Carbon::createFromTimestamp($expires), $params, false);

        return URL::to($relative);
    }

    public function download(Attachment $attachment, bool $thumb): Response
    {
        $path = $thumb && $attachment->thumb_path ? $attachment->thumb_path : $attachment->path;
        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($path)) {
            throw MessagingException::notFound('File not found.');
        }

        $driver = config("filesystems.disks.{$attachment->disk}.driver");
        if ($driver !== 'local' && config('messaging.attachments.use_disk_temporary_urls', true)
            && method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(5), [
                'ResponseContentDisposition' => ($attachment->isImage() ? 'inline' : 'attachment')
                    .'; filename="'.addcslashes($attachment->original_name, '"\\').'"',
            ]));
        }

        $mime = $thumb && $attachment->thumb_path ? $this->thumbMime($attachment->thumb_path) : $attachment->mime;

        return $disk->response($path, $attachment->original_name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ], $attachment->isImage() ? 'inline' : 'attachment');
    }

    private function makeThumbnail(string|false $sourcePath, string $disk, string $targetBase): ?string
    {
        if (! $sourcePath || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $info = @getimagesize($sourcePath);
            if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
                return null;
            }
            $src = @imagecreatefromstring((string) file_get_contents($sourcePath));
            if (! $src) {
                return null;
            }

            [$w, $h] = [$info[0], $info[1]];
            $max = (int) config('messaging.attachments.thumbnail_max', 320);
            $scale = min(1, $max / max($w, $h));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($tw, $th);
            $hasAlpha = in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
            if ($hasAlpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

            ob_start();
            $hasAlpha ? imagepng($dst, null, 6) : imagejpeg($dst, null, 80);
            $bytes = (string) ob_get_clean();

            $target = $targetBase.($hasAlpha ? '.png' : '.jpg');
            Storage::disk($disk)->put($target, $bytes, ['visibility' => 'private']);

            return $target;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function thumbMime(string $path): string
    {
        return str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'file' : mb_substr($name, 0, 200);
    }
}
