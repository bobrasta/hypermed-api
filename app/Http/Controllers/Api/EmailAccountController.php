<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailAccountResource;
use App\Models\EmailAccount;
use Illuminate\Http\Request;
use Webklex\PHPIMAP\ClientManager;

class EmailAccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = EmailAccount::where('user_id', $request->user()->id)->get();

        return EmailAccountResource::collection($accounts);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label'           => ['required', 'string'],
            'imap_host'       => ['required', 'string'],
            'imap_port'       => ['nullable', 'integer'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,starttls,none'],
            'smtp_host'       => ['required', 'string'],
            'smtp_port'       => ['nullable', 'integer'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls,starttls,none'],
            'username'        => ['required', 'string'],
            'password'        => ['required', 'string'],
            'from_name'       => ['nullable', 'string'],
            'from_email'      => ['required', 'email'],
            'is_default'      => ['nullable', 'boolean'],
        ]);

        $data['user_id'] = $request->user()->id;

        if (! empty($data['is_default'])) {
            EmailAccount::where('user_id', $request->user()->id)
                ->update(['is_default' => false]);
        }

        $account = EmailAccount::create($data);

        return response()->json(['data' => new EmailAccountResource($account)], 201);
    }

    public function show(Request $request, EmailAccount $emailAccount)
    {
        abort_if($emailAccount->user_id !== $request->user()->id, 403);

        return response()->json(['data' => new EmailAccountResource($emailAccount)]);
    }

    public function update(Request $request, EmailAccount $emailAccount)
    {
        abort_if($emailAccount->user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'label'           => ['sometimes', 'string'],
            'imap_host'       => ['sometimes', 'string'],
            'imap_port'       => ['nullable', 'integer'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,starttls,none'],
            'smtp_host'       => ['sometimes', 'string'],
            'smtp_port'       => ['nullable', 'integer'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls,starttls,none'],
            'username'        => ['sometimes', 'string'],
            'password'        => ['sometimes', 'string'],
            'from_name'       => ['nullable', 'string'],
            'from_email'      => ['sometimes', 'email'],
            'is_default'      => ['nullable', 'boolean'],
            'is_active'       => ['nullable', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            EmailAccount::where('user_id', $request->user()->id)
                ->where('id', '!=', $emailAccount->id)
                ->update(['is_default' => false]);
        }

        $emailAccount->update($data);

        return response()->json(['data' => new EmailAccountResource($emailAccount)]);
    }

    public function destroy(Request $request, EmailAccount $emailAccount)
    {
        abort_if($emailAccount->user_id !== $request->user()->id, 403);

        $emailAccount->delete();

        return response()->json(null, 204);
    }

    public function test(Request $request, EmailAccount $emailAccount)
    {
        abort_if($emailAccount->user_id !== $request->user()->id, 403);

        if (! extension_loaded('imap')) {
            return response()->json([
                'data' => ['connected' => false, 'error' => 'PHP IMAP extension is not loaded on this server.'],
            ], 422);
        }

        try {
            $encryption = $emailAccount->imap_encryption === 'none' ? false : $emailAccount->imap_encryption;

            $cm     = new ClientManager();
            $client = $cm->make([
                'host'          => $emailAccount->imap_host,
                'port'          => $emailAccount->imap_port,
                'encryption'    => $encryption,
                'validate_cert' => false,
                'username'      => $emailAccount->username,
                'password'      => $emailAccount->password,
                'protocol'      => 'imap',
                'timeout'       => 15,
            ]);

            $client->connect();
            $folders = $client->getFolders(false);
            $client->disconnect();

            $folderNames = collect($folders)->map(fn ($f) => (object) $f)->map(fn ($f) => $f->full_name)->values();

            return response()->json([
                'data' => [
                    'connected' => true,
                    'folders'   => $folderNames,
                    'hint'      => count($folderNames) === 0 ? 'Connected but no folders found — check IMAP permissions.' : null,
                ],
            ]);
        } catch (\Exception $e) {
            // webklex wraps both connection and auth failures as "connection setup failed"
            // Unwrap the chain to get the real cause
            $cause = $e;
            while ($cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }

            $causeClass = class_basename($cause);
            $causeMsg   = $cause->getMessage();
            $fullDetail = $e->getMessage() . ($causeMsg !== $e->getMessage() ? ' → ' . $causeMsg : '');

            $hint = match(true) {
                $causeClass === 'AuthFailedException',
                str_contains($causeMsg, 'Authentication'),
                str_contains($causeMsg, 'credentials'),
                str_contains($causeMsg, 'LOGIN failed')
                    => 'Authentication failed — check your password. For Gmail/Outlook use an App Password, not your account password.',
                str_contains($causeMsg, 'Too many')
                    => 'Too many failed logins. Wait a few minutes then try again.',
                str_contains($fullDetail, 'certificate'),
                str_contains($fullDetail, 'SSL')
                    => 'SSL error — certificate could not be verified.',
                str_contains($causeMsg, 'timed out'),
                str_contains($causeMsg, 'timeout')
                    => 'Connection timed out — check the IMAP host and port.',
                str_contains($causeMsg, 'refused'),
                str_contains($causeMsg, 'connection refused')
                    => 'Connection refused — verify the IMAP host and port are correct.',
                str_contains($causeMsg, 'unsupported protocol')
                    => 'Unsupported protocol specified.',
                default => 'Could not connect — check host, port, and encryption settings.',
            };

            return response()->json([
                'data' => ['connected' => false, 'error' => $hint, 'detail' => $fullDetail],
            ], 422);
        }
    }
}
