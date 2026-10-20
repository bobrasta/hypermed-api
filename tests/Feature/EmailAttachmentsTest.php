<?php

namespace Tests\Feature;

use App\Jobs\SyncEmailsJob;
use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Email attachments end to end against a real IMAP/SMTP server: full-size
// sync (up to the 30 MB mail-server cap), files kept on the server, owner-only
// download, backfill for emails synced before files were kept, and sending
// with uploaded / forwarded attachments.
//
// Opt-in: needs Postgres and a GreenMail server with users diana:pw and
// other:pw on 127.0.0.1 (IMAP 3143, SMTP 3025):
//   docker run --rm -p 3143:3143 -p 3025:3025 -e GREENMAIL_OPTS='-Dgreenmail.setup.test.smtp
//     -Dgreenmail.setup.test.imap -Dgreenmail.users=diana:pw@local.test,other:pw@local.test
//     -Dgreenmail.hostname=0.0.0.0' docker.io/greenmail/standalone:2.1.2
//   GREENMAIL=1 DB_CONNECTION=pgsql DB_DATABASE=hypermed vendor/bin/phpunit tests/Feature/EmailAttachmentsTest.php
class EmailAttachmentsTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;
    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('GREENMAIL') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs GREENMAIL=1, a GreenMail server and Postgres');
        }
        Storage::fake('local');

        $this->owner = User::forceCreate(['name' => 'Mail Owner', 'email' => 'mail-owner@example.com',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true]);
        $this->account = $this->account($this->owner, 'diana');
        $this->purgeMailbox('diana');
        Sanctum::actingAs($this->owner);
    }

    private function account(User $u, string $login): EmailAccount
    {
        return EmailAccount::create(['user_id' => $u->id, 'label' => 'test', 'imap_host' => '127.0.0.1', 'imap_port' => 3143,
            'imap_encryption' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 3025, 'smtp_encryption' => 'none',
            'username' => $login, 'password' => 'pw', 'from_name' => ucfirst($login), 'from_email' => "{$login}@local.test",
            'is_default' => true, 'is_active' => true]);
    }

    private function purgeMailbox(string $login): void
    {
        $client = \App\Services\Email\EmailAttachmentStore::client($this->account);
        $client->connect();
        foreach ($client->getFolder('INBOX')->messages()->all()->get() as $m) {
            $m->delete(false);
        }
        $client->expunge();
        $client->disconnect();
    }

    /** Sends a MIME message straight into diana's inbox over SMTP. */
    private function deliver(string $subject, array $parts): void
    {
        $b = 'b' . md5($subject);
        $mime = "From: Sender <sender@local.test>\r\nTo: diana@local.test\r\nSubject: {$subject}\r\nDate: " . date(DATE_RFC2822)
            . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$b}\"\r\n\r\n"
            . "--{$b}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody of {$subject}\r\n";
        foreach ($parts as [$name, $mimeType, $bytes, $disposition, $cid]) {
            $mime .= "--{$b}\r\nContent-Type: {$mimeType}; name=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: {$disposition}; filename=\"{$name}\"\r\n" . ($cid ? "Content-ID: <{$cid}>\r\n" : '')
                . "\r\n" . chunk_split(base64_encode($bytes)) . "\r\n";
        }
        $mime .= "--{$b}--\r\n";

        $s = fsockopen('127.0.0.1', 3025);
        // Replies can be multi-line ("250-…" then "250 …"): read to the last line.
        $reply = function () use ($s) {
            do { $line = fgets($s); } while ($line !== false && ($line[3] ?? ' ') === '-');
            return (string) $line;
        };
        $reply();
        foreach (["HELO t", "MAIL FROM:<sender@local.test>", "RCPT TO:<diana@local.test>", "DATA"] as $cmd) {
            fwrite($s, "{$cmd}\r\n");
            $reply();
        }
        $data = str_replace("\r\n.", "\r\n..", $mime) . "\r\n.\r\n";
        for ($off = 0; $off < strlen($data); $off += $w) {
            $w = fwrite($s, substr($data, $off, 65536));
        }
        $this->assertStringStartsWith('250', $reply(), "GreenMail refused {$subject}");
        fwrite($s, "QUIT\r\n");
        fclose($s);
    }

    public function test_large_email_syncs_in_full_and_attachments_open_in_app(): void
    {
        @ini_set('memory_limit', '256M'); // what production gives a request before the sync raises it
        $pdf = '%PDF-1.4 ' . random_bytes(21 * 1024 * 1024); // ~28 MB once base64-encoded
        $this->deliver('Big tender pack', [
            ['tender.pdf', 'application/pdf', $pdf, 'attachment', null],
            ['logo.png', 'image/png', random_bytes(3000), 'inline', 'logo1'],
            ['fault.png', 'image/png', random_bytes(60 * 1024), 'inline', 'shot1'],
        ]);

        $n = (new SyncEmailsJob($this->account->id, 'INBOX', 25))->handle();
        $this->assertSame(1, $n);

        $email = SyncedEmail::where('email_account_id', $this->account->id)->firstOrFail();
        $this->assertSame('Body of Big tender pack', trim($email->body_text));
        $this->assertTrue($email->has_attachments);
        // Signature-size inline image dropped, pasted screenshot kept.
        $this->assertEqualsCanonicalizing(['tender.pdf', 'fault.png'], $email->attachments->pluck('filename')->all());

        $att = $email->attachments->firstWhere('filename', 'tender.pdf');
        $shown = $this->getJson("/api/v1/emails/{$email->id}")->assertOk()->json('data.attachments');
        $this->assertCount(2, $shown);

        $res = $this->get("/api/v1/emails/{$email->id}/attachments/{$att->id}")->assertOk();
        $this->assertSame(strlen($pdf), strlen($res->streamedContent()));

        // Not the owner: refused.
        $other = User::forceCreate(['name' => 'Other', 'email' => 'mail-other@example.com', 'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($other);
        $this->get("/api/v1/emails/{$email->id}/attachments/{$att->id}")->assertForbidden();
    }

    public function test_emails_synced_before_files_were_kept_are_backfilled_on_open(): void
    {
        $this->deliver('Old quote', [['quote.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', random_bytes(40000), 'attachment', null]]);
        (new SyncEmailsJob($this->account->id, 'INBOX', 25))->handle();
        $email = SyncedEmail::where('email_account_id', $this->account->id)->firstOrFail();
        // Simulate the old sync: name-only row, no file.
        Storage::disk('local')->deleteDirectory("email-attachments/{$this->account->id}");
        $email->attachments()->update(['storage_path' => null]);

        $shown = $this->getJson("/api/v1/emails/{$email->id}")->assertOk()->json('data.attachments');
        $this->assertSame('quote.xlsx', $shown[0]['filename']);
        $this->get("/api/v1/emails/{$email->id}/attachments/{$shown[0]['id']}")->assertOk();
    }

    public function test_compose_and_forward_send_attachments(): void
    {
        $up = $this->post('/api/v1/emails/attachments', ['file' => UploadedFile::fake()->createWithContent('specs.pdf', '%PDF-1.4 specs')],
            ['Accept' => 'application/json'])->assertCreated()->json('data');

        $sent = $this->postJson('/api/v1/emails/compose', [
            'to' => [['email' => 'other@local.test']], 'subject' => 'Specs', 'body' => 'See attached',
            'attachments' => [$up['token']],
        ])->assertCreated()->json('data');
        $this->assertSame('specs.pdf', $sent['attachments'][0]['filename']);
        // The staged upload is used up.
        $this->postJson('/api/v1/emails/compose', ['to' => [['email' => 'other@local.test']], 'subject' => 'x', 'body' => 'x',
            'attachments' => [$up['token']]])->assertStatus(422);

        // Someone else's token is refused.
        $mine = $this->post('/api/v1/emails/attachments', ['file' => UploadedFile::fake()->create('a.pdf', 5)], ['Accept' => 'application/json'])->json('data.token');
        Sanctum::actingAs(User::forceCreate(['name' => 'X', 'email' => 'mail-x@example.com', 'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true]));
        $this->account(auth()->user(), 'other');
        $this->postJson('/api/v1/emails/compose', ['to' => [['email' => 'diana@local.test']], 'subject' => 'x', 'body' => 'x',
            'attachments' => [$mine]])->assertStatus(422);
        Sanctum::actingAs($this->owner);

        // Forward carries the original's files.
        $this->deliver('Invoice', [['inv.pdf', 'application/pdf', '%PDF-1.4 inv', 'attachment', null]]);
        (new SyncEmailsJob($this->account->id, 'INBOX', 25))->handle();
        $inv = SyncedEmail::where('email_account_id', $this->account->id)->where('subject', 'Invoice')->firstOrFail();
        $fwd = $this->postJson("/api/v1/emails/{$inv->id}/forward", ['to' => [['email' => 'other@local.test']], 'body' => 'FYI'])
            ->assertCreated()->json('data');
        $this->assertSame(['inv.pdf'], array_column($fwd['attachments'], 'filename'));

        // Over the 22 MB outgoing limit: refused before sending.
        $big = $this->post('/api/v1/emails/attachments', ['file' => UploadedFile::fake()->createWithContent('big.bin', str_repeat('a', 12 * 1024 * 1024))], ['Accept' => 'application/json'])->json('data.token');
        $big2 = $this->post('/api/v1/emails/attachments', ['file' => UploadedFile::fake()->createWithContent('big2.bin', str_repeat('b', 11 * 1024 * 1024))], ['Accept' => 'application/json'])->json('data.token');
        $this->postJson('/api/v1/emails/compose', ['to' => [['email' => 'other@local.test']], 'subject' => 'x', 'body' => 'x',
            'attachments' => [$big, $big2]])->assertStatus(422)->assertJsonFragment(['message' => 'Attachments total 23 MB — the limit is 22 MB per email.']);
    }
}
