<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data deletion — Autonomia Joan Paneque</title>
    <style>
        :root {
            --bg: #f4f3f0;
            --paper: #fdfcfa;
            --ink: #1c1b19;
            --muted: #5c5a56;
            --rule: #c9c5be;
            --accent: #243b53;
            --ok-bg: #e8f2eb;
            --ok-border: #2d6a4f;
            --ok-text: #1b4332;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 1rem;
            line-height: 1.65;
            color: var(--ink);
            background: var(--bg);
        }
        .wrap {
            max-width: 42rem;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
        }
        article {
            background: var(--paper);
            border: 1px solid var(--rule);
            border-radius: 2px;
            padding: 2.25rem 2rem 2.5rem;
            box-shadow: 0 1px 0 rgba(28, 27, 25, 0.06);
        }
        h1 {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 1.65rem;
            font-weight: 600;
            letter-spacing: -0.02em;
            line-height: 1.25;
            margin: 0 0 0.35rem;
            color: var(--accent);
        }
        .meta {
            font-size: 0.875rem;
            color: var(--muted);
            margin: 0 0 2rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--rule);
        }
        h2 {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 1.125rem;
            font-weight: 600;
            margin: 1.75rem 0 0.65rem;
            color: var(--accent);
        }
        h2:first-of-type { margin-top: 0; }
        p { margin: 0 0 1rem; }
        ul {
            margin: 0 0 1rem;
            padding-left: 1.25rem;
        }
        li { margin-bottom: 0.35rem; }
        a {
            color: var(--accent);
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        a:hover { text-decoration: none; }
        .lead {
            font-size: 1.02rem;
            color: var(--ink);
        }
        .footer-links {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--rule);
            font-size: 0.875rem;
            color: var(--muted);
        }
        .success {
            background: var(--ok-bg);
            border: 1px solid var(--ok-border);
            color: var(--ok-text);
            padding: 1rem 1.15rem;
            border-radius: 2px;
            margin: 0 0 1.5rem;
        }
        .success strong { display: block; margin-bottom: 0.35rem; }
        label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin: 0 0 0.35rem;
            color: var(--ink);
        }
        .field { margin-bottom: 1.1rem; }
        .hint {
            font-size: 0.8125rem;
            color: var(--muted);
            margin: 0.25rem 0 0;
        }
        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 0.55rem 0.65rem;
            font: inherit;
            border: 1px solid var(--rule);
            border-radius: 2px;
            background: #fff;
        }
        textarea {
            min-height: 5rem;
            resize: vertical;
        }
        button[type="submit"] {
            font: inherit;
            font-weight: 600;
            padding: 0.6rem 1.25rem;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 2px;
            cursor: pointer;
        }
        button[type="submit"]:hover {
            filter: brightness(1.08);
        }
        .alt-contact {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--rule);
            font-size: 0.9375rem;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <div class="wrap">
        <article>
            <h1>Data deletion request</h1>
            <p class="meta">Autonomia Joan Paneque &middot; Last updated: {{ $lastUpdated }}</p>

            @if (session('meta_data_submitted'))
                <div class="success" role="status">
                    <strong>Request received</strong>
                    Thank you. Your request has been recorded successfully. If any data associated with that Instagram account existed in our systems, it will be deleted.
                </div>
            @endif

            <p class="lead">
                If you have interacted with automated content from <strong>Autonomia Joan Paneque</strong> on Instagram and want us to remove information linked to your profile, use the form below or contact us by email.
            </p>

            @unless (session('meta_data_submitted'))
                <h2>Form</h2>
                <p>Enter your Instagram username (no password required). This is only used to identify which account should be included in deletion.</p>

                <form method="post" action="{{ route('meta.data-deletion.store') }}" autocomplete="on">
                    <div class="field">
                        <label for="instagram_username">Instagram username</label>
                        <input type="text" id="instagram_username" name="instagram_username" placeholder="@your_username or your_username" autocomplete="username">
                        <p class="hint">Example: <code>@my_account</code> or <code>my_account</code></p>
                    </div>
                    <div class="field">
                        <label for="contact_email">Email (optional)</label>
                        <input type="email" id="contact_email" name="contact_email" placeholder="in case we need to reply" autocomplete="email">
                    </div>
                    <div class="field">
                        <label for="notes">Optional details</label>
                        <textarea id="notes" name="notes" placeholder="Post, approximate date, or anything that helps locate the interaction"></textarea>
                    </div>
                    <button type="submit">Submit deletion request</button>
                </form>
            @else
                <p class="hint" style="margin-top:0">Need to send another request? <a href="{{ route('meta.data-deletion') }}">Reload this page</a>.</p>
            @endunless

            <div class="alt-contact">
                <strong>Alternatively,</strong> email
                <a href="mailto:hola@joanpaneque.com?subject=Data%20deletion%20request%20-%20Autonomia%20Joan%20Paneque">hola@joanpaneque.com</a>
                with your Instagram username and, if you like, the same kind of detail as in the form.
            </div>

            <h2>Other steps you can take</h2>
            <ul>
                <li>You can <strong>delete your comment</strong> on the Instagram post; that ends the associated interaction.</li>
                <li>On Instagram or Facebook, use <strong>Settings and privacy &gt; Apps and websites</strong> to review or remove access for connected apps.</li>
            </ul>

            <p class="footer-links">
                More information: <a href="{{ route('meta.privacy') }}">Privacy policy</a>
            </p>
        </article>
    </div>
</body>
</html>
