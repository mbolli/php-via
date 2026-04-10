<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Contact Form Example.
 *
 * Demonstrates multipart file uploads and server-side form validation:
 *  - Form submitted as multipart/form-data (Datastar contentType: 'form')
 *  - Text fields arrive in $c->input(), uploaded file in $c->file()
 *  - Per-field validation; state shared between closures via PHP references (no signals needed)
 *  - HTML5 browser-side validation fires before the request is even sent
 *  - Success state toggled server-side; Twig {% if %} switches form <-> confirmation panel
 */
final class ContactFormExample {
    public const string SLUG = 'contact-form';

    /** @var list<string> Allowed MIME types for the attachment */
    private const array ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain'];

    /** Maximum upload size: 2 MB */
    private const int MAX_BYTES = 2 * 1024 * 1024;

    public static function register(Via $app): void {
        $app->page('/examples/contact-form', function (Context $c): void {
            // ── Shared mutable state between action and view closures ─────
            // Plain PHP references: nothing here is client-reactive, so Signal
            // would be the wrong abstraction. The block re-renders server-side
            // on every sync() call and Twig reads the current values directly.
            // TODO: replace with $c->state() once the framework ships a
            //       lightweight ephemeral container without signal machinery.
            $nameError = '';
            $emailError = '';
            $messageError = '';
            $fileError = '';
            $submitted = false;
            $submittedFile = '';
            $submittedFileInfo = ''; // e.g. "PDF · 42 kB"

            // ── Submit action ─────────────────────────────────────────────
            $submit = $c->action(function () use (
                $c,
                &$nameError,
                &$emailError,
                &$messageError,
                &$fileError,
                &$submitted,
                &$submittedFile,
                &$submittedFileInfo,
            ): void {
                $nameError = '';
                $emailError = '';
                $messageError = '';
                $fileError = '';

                $valid = true;

                // ── Name ─────────────────────────────────────────────────
                $name = mb_trim((string) $c->input('name', ''));
                if ($name === '') {
                    $nameError = 'Name is required.';
                    $valid = false;
                } elseif (mb_strlen($name) < 2) {
                    $nameError = 'Name must be at least 2 characters.';
                    $valid = false;
                }

                // ── Email ────────────────────────────────────────────────
                $email = mb_trim((string) $c->input('email', ''));
                if ($email === '') {
                    $emailError = 'Email is required.';
                    $valid = false;
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emailError = 'Enter a valid email address.';
                    $valid = false;
                }

                // ── Message ───────────────────────────────────────────────
                $message = mb_trim((string) $c->input('message', ''));
                if ($message === '') {
                    $messageError = 'Message is required.';
                    $valid = false;
                } elseif (mb_strlen($message) < 10) {
                    $messageError = 'Message must be at least 10 characters.';
                    $valid = false;
                }

                // ── Optional file attachment ──────────────────────────────
                $file = $c->file('attachment');
                $savedFileName = '';
                $savedFileInfo = '';
                if ($file !== null) {
                    if ($file['size'] > self::MAX_BYTES) {
                        $fileError = 'File is too large. Maximum size is 2 MB.';
                        $valid = false;
                    } elseif (!\in_array($file['type'], self::ALLOWED_MIME, strict: true)) {
                        $fileError = 'File type not allowed. Use JPEG, PNG, GIF, PDF, or plain text.';
                        $valid = false;
                    } else {
                        $savedFileName = $file['name'];
                        $typeLabel = match ($file['type']) {
                            'image/jpeg' => 'JPEG',
                            'image/png' => 'PNG',
                            'image/gif' => 'GIF',
                            'application/pdf' => 'PDF',
                            default => strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION)),
                        };
                        $bytes = $file['size'];
                        $sizeLabel = $bytes >= 1_048_576
                            ? round($bytes / 1_048_576, 1) . ' MB'
                            : ceil($bytes / 1024) . ' kB';
                        $savedFileInfo = $typeLabel . ' · ' . $sizeLabel;
                    }
                }

                if (!$valid) {
                    $c->sync();

                    return;
                }

                // All valid — in a real app you'd send an email / persist to DB here.
                $submittedFile = $savedFileName;
                $submittedFileInfo = $savedFileInfo;
                $submitted = true;
                $c->sync();
            }, 'submit');

            $c->view(function () use (
                $c,
                &$nameError,
                &$emailError,
                &$messageError,
                &$fileError,
                &$submitted,
                &$submittedFile,
                &$submittedFileInfo,
                $submit,
            ): string {
                return $c->render('examples/contact-form.html.twig', [
                    'title' => '📬 Contact Form',
                    'description' => 'Multipart file upload and server-side form validation. The form submits as <code>multipart/form-data</code>; text fields arrive in <code>$c->input()</code>, the file in <code>$c->file()</code>. Per-field error signals are pushed back via SSE.',
                    'summary' => [
                        '<strong>No base64 overhead.</strong> Datastar\'s <code>contentType: \'form\'</code> modifier submits the nearest <code>&lt;form enctype="multipart/form-data"&gt;</code> as a real multipart POST — files travel as binary, not JSON blobs.',
                        '<strong>Two validation layers.</strong> HTML5 <code>required</code> / <code>type="email"</code> / <code>minlength</code> attributes make Datastar call <code>reportValidity()</code> before the request is even sent. The server then re-validates every field independently.',
                        '<strong><code>$c->file(\'attachment\')</code></strong> returns the parsed upload array (<code>name</code>, <code>type</code>, <code>tmp_name</code>, <code>size</code>) if the upload succeeded, or <code>null</code> if no file was sent or the upload failed.',
                        '<strong>State shared via PHP references.</strong> The action and view closures share mutable variables with <code>use (&amp;$ref)</code>. No signals needed — nothing is client-reactive. The SSE block re-render reads the updated values directly via Twig.',
                        '<strong>Success toggled server-side.</strong> On clean submission <code>$submitted</code> becomes <code>true</code>; the re-rendered block switches to the confirmation panel — no page reload, no client branching.',
                    ],
                    'anatomy' => [
                        'signals' => [],
                        'actions' => [
                            ['name' => 'submit', 'desc' => 'Validates all fields server-side. Mutates shared PHP reference variables on failure or on success, then calls $c->sync() to trigger a block re-render via SSE.'],
                        ],
                        'views' => [
                            ['name' => 'contact-form.html.twig', 'desc' => 'Form with inline error signals and a success panel. Submitted as multipart/form-data via Datastar contentType: form.'],
                        ],
                    ],
                    'githubLinks' => [
                        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ContactFormExample.php'],
                        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/contact-form.html.twig'],
                    ],
                    'nameError' => $nameError,
                    'emailError' => $emailError,
                    'messageError' => $messageError,
                    'fileError' => $fileError,
                    'submitted' => $submitted,
                    'submittedFile' => $submittedFile,
                    'submittedFileInfo' => $submittedFileInfo,
                    'ctxId' => $c->getId(),
                    'submit' => $submit,
                ]);
            }, block: 'demo', cacheUpdates: false);
        });
    }
}
