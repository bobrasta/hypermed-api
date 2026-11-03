<?php

use Hypermed\Messaging\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    config(['messaging.attachments.disk' => 'local']);
});

function msgRelativeUrl(string $absolute): string
{
    $parts = parse_url($absolute);

    return $parts['path'].'?'.$parts['query'];
}

it('uploads an image, generates a thumbnail and serves both via signed URLs', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    $res = $this->postJson(msgApi("conversations/{$c->id}/attachments"), [
        'client_uuid' => (string) Str::uuid(),
        'file' => UploadedFile::fake()->image('photo.jpg', 1200, 800),
        'body' => 'Look at this',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'file')
        ->assertJsonPath('data.body', 'Look at this')
        ->assertJsonPath('data.attachments.0.original_name', 'photo.jpg')
        ->assertJsonPath('data.attachments.0.mime', 'image/jpeg')
        ->assertJsonPath('data.attachments.0.is_image', true);

    $att = Attachment::query()->firstOrFail();
    Storage::disk('local')->assertExists($att->path);
    Storage::disk('local')->assertExists($att->thumb_path);
    [$tw, $th] = getimagesizefromstring(Storage::disk('local')->get($att->thumb_path));
    expect(max($tw, $th))->toBe(320);

    $url = $res->json('data.attachments.0.url');
    $thumb = $res->json('data.attachments.0.thumb_url');
    expect($url)->toStartWith('http')->toContain('signature=')->and($thumb)->toContain('thumb=1');

    // No bearer token needed.
    $this->app['auth']->forgetGuards();
    $file = $this->get(msgRelativeUrl($url))->assertOk();
    expect($file->headers->get('content-type'))->toBe('image/jpeg');
    $this->get(msgRelativeUrl($thumb))->assertOk();

    // Tampered and expired links are refused.
    $this->get(str_replace('thumb=1', 'thumb=0', msgRelativeUrl($thumb)))->assertForbidden();
    $this->travel(16)->minutes();
    $this->get(msgRelativeUrl($url))->assertForbidden()->assertJsonPath('code', 'forbidden');
});

it('stores non-image files without thumbnails and as downloads', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    $res = $this->postJson(msgApi("conversations/{$c->id}/attachments"), [
        'client_uuid' => (string) Str::uuid(),
        'file' => UploadedFile::fake()->create('report.pdf', 120, 'application/pdf'),
    ])->assertCreated()
        ->assertJsonPath('data.attachments.0.is_image', false)
        ->assertJsonPath('data.attachments.0.thumb_url', null)
        ->assertJsonPath('data.attachments.0.size', 120 * 1024);

    $download = $this->get(msgRelativeUrl($res->json('data.attachments.0.url')))->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment')->toContain('report.pdf');
});

it('is idempotent for attachment uploads', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);
    $uuid = (string) Str::uuid();

    $first = $this->postJson(msgApi("conversations/{$c->id}/attachments"), ['client_uuid' => $uuid, 'file' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')])->assertCreated();
    $second = $this->postJson(msgApi("conversations/{$c->id}/attachments"), ['client_uuid' => $uuid, 'file' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')])->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Attachment::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('rejects dangerous or oversized files', function () {
    config(['messaging.attachments.max_size_kb' => 50]);
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$c->id}/attachments"), ['client_uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('evil.php', 1, 'text/plain')])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->postJson(msgApi("conversations/{$c->id}/attachments"), ['client_uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('big.zip', 51, 'application/zip')])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['file']]);
    $this->postJson(msgApi("conversations/{$c->id}/attachments"), ['client_uuid' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['file']]);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('hides attachments of deleted messages', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);
    $res = $this->postJson(msgApi("conversations/{$c->id}/attachments"), ['client_uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->image('p.png', 50, 50)])->assertCreated();
    $url = $res->json('data.attachments.0.url');

    $this->deleteJson(msgApi('messages/'.$res->json('data.id')))->assertOk()->assertJsonPath('data.attachments', []);
    $this->get(msgRelativeUrl($url))->assertNotFound();
});
