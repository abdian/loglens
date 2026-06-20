<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Browsing\Browser;
use LogLens\Mail\MailPreview;
use LogLens\Security\Redactor;

/**
 * Mail preview endpoint. Renders a logged MIME
 * message (MAIL_MAILER=log) as headers + HTML/plain parts + attachment list.
 */
class MailController extends Controller
{
    public function __construct(
        private Browser $browser,
        private Redactor $redactor
    ) {
    }

    public function preview(Request $request, string $file, int $seq): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);
        $entry = $this->browser->entry($file, $seq, true);
        if (! $entry) {
            return $this->error('not_found', 'Entry not found.', 404);
        }

        $mail = MailPreview::extract($entry['raw']);
        if (! $mail) {
            return $this->error('not_mail', 'This entry does not contain a logged email.', 422);
        }

        // Sanitize attacker-controlled HTML, then redact secrets, in UI/API/copy
        // paths. The client renders `html` inside a sandboxed iframe.
        $mail['html'] = $mail['html'] !== null
            ? $this->redactor->redact(MailPreview::sanitizeHtml($mail['html']))
            : null;
        $mail['text'] = $mail['text'] !== null ? $this->redactor->redact($mail['text']) : null;
        $mail['untrusted'] = true;

        return $this->json($mail);
    }
}
