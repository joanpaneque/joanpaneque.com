<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of service — Autonomia Joan Paneque</title>
    <style>
        :root {
            --bg: #f4f3f0;
            --paper: #fdfcfa;
            --ink: #1c1b19;
            --muted: #5c5a56;
            --rule: #c9c5be;
            --accent: #243b53;
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
    </style>
</head>
<body>
    <div class="wrap">
        <article>
            <h1>Terms of service</h1>
            <p class="meta">Autonomia Joan Paneque &middot; Last updated: {{ $lastUpdated }}</p>

            <p class="lead">
                These Terms of Service (&ldquo;Terms&rdquo;) govern access to and use of <strong>Autonomia Joan Paneque</strong> (the &ldquo;Application&rdquo;). The Application connects to the Instagram platform through Meta&rsquo;s APIs to automate interactions on a <strong>single, owner-operated Instagram account</strong>. It is <strong>not</strong> a public service, marketplace, or platform for other businesses or end users to sign up and run their own automation.
            </p>

            <h2>1. The service</h2>
            <p>The Application is used only by the account owner to:</p>
            <ul>
                <li>Detect <strong>keywords</strong> in comments on the owner&rsquo;s own posts (for example, a word such as &ldquo;AI&rdquo;).</li>
                <li>Send <strong>automated replies</strong> to those comments when the configured rules match.</li>
                <li>Optionally send a <strong>private message</strong> to the commenter when that behaviour is part of the owner&rsquo;s configured flow and the user has initiated the interaction (for example, by using the keyword).</li>
            </ul>
            <p>Processing is limited to what is needed for that automation. We do not operate a consumer-facing product where unrelated third parties create accounts or upload bulk data through our Application.</p>

            <h2>2. Relationship to Meta and Instagram</h2>
            <p>
                Use of the Application is also subject to <a href="https://developers.facebook.com/terms" rel="noopener noreferrer">Meta&rsquo;s Platform Terms</a>, the <a href="https://developers.facebook.com/policy" rel="noopener noreferrer">Meta Platform Policy</a>, and Instagram&rsquo;s rules and community standards as they apply. If these Terms conflict with Meta&rsquo;s requirements, Meta&rsquo;s terms and policies prevail for use of Meta&rsquo;s products and APIs.
            </p>

            <h2>3. Acceptable use</h2>
            <p>You agree that the Application will not be used to spam, deceive users, scrape data for unrelated purposes, or violate any applicable law. Automated messages should be clearly in line with how the owner has described the experience to their audience and with Meta&rsquo;s policies.</p>

            <h2>4. No warranty</h2>
            <p>The Application is provided &ldquo;as is&rdquo; for the owner&rsquo;s internal use. We do not guarantee uninterrupted or error-free operation. API or platform changes by Meta may affect functionality.</p>

            <h2>5. Limitation of liability</h2>
            <p>To the maximum extent permitted by law, the operator of the Application is not liable for indirect or consequential loss arising from use of the Application or from actions taken (or not taken) on Instagram. Nothing in these Terms limits liability that cannot be limited under mandatory law.</p>

            <h2>6. Changes</h2>
            <p>We may update these Terms from time to time. The &ldquo;Last updated&rdquo; date at the top of this page will change when we do. Continued use of the Application after changes means you accept the updated Terms.</p>

            <h2>7. Contact</h2>
            <p>Questions about these Terms: <a href="mailto:hola@joanpaneque.com">hola@joanpaneque.com</a></p>

            <p class="footer-links">
                Also see: <a href="{{ route('meta.privacy') }}">Privacy policy</a> &middot; <a href="{{ route('meta.data-deletion') }}">Data deletion</a>
            </p>
        </article>
    </div>
</body>
</html>
