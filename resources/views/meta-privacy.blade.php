<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy policy — Autonomia Joan Paneque</title>
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
    </style>
</head>
<body>
    <div class="wrap">
        <article>
            <h1>Privacy policy: Autonomia Joan Paneque</h1>
            <p class="meta">Last updated: {{ $lastUpdated }}</p>

            <p class="lead">
                This Privacy Policy explains how we process information when you interact with <strong>Autonomia Joan Paneque</strong>, our internal Instagram automation application (the &ldquo;Application&rdquo;). We comply with the General Data Protection Regulation (GDPR) and Meta&rsquo;s Platform Policies.
            </p>

            <h2>1. Data controller</h2>
            <p><strong>Controller:</strong> {{ $controllerName }}</p>
            <p><strong>Contact:</strong> <a href="mailto:hola@joanpaneque.com">hola@joanpaneque.com</a></p>

            <h2>2. Data we collect and purposes</h2>
            <p>Through the Instagram Graph API, we strictly access:</p>
            <ul>
                <li><strong>User identifiers (PSID/IGSID):</strong> solely to direct the reply to the correct user.</li>
                <li><strong>Comment and message content:</strong> to detect trigger keywords and run the automated response.</li>
                <li><strong>Post ID:</strong> to identify where the technical action should be performed.</li>
            </ul>
            <p>
                <strong>Purpose:</strong> the only purpose is automating replies to comments and sending follow-up messages that users request by using keywords. We do not profile users or conduct unsolicited bulk marketing.
            </p>

            <h2>3. Legal basis</h2>
            <p>We process this data on the basis of:</p>
            <ul>
                <li><strong>Consent:</strong> by posting a specific keyword, the user voluntarily starts the interaction.</li>
                <li><strong>Legitimate interests:</strong> to manage interactions efficiently on our own official account.</li>
            </ul>

            <h2>4. Retention</h2>
            <p>
                We do not store data permanently. The Application processes information in real time to deliver the response, and data is cleared from our server memory promptly after Meta&rsquo;s API confirms delivery of the reply. We do not maintain databases of user histories.
            </p>

            <h2>5. Your rights and data deletion</h2>
            <p>In line with Meta&rsquo;s policies, users may request deletion of their data or revoke access as follows:</p>
            <ul>
                <li><strong>Self-service removal:</strong> users may delete their comment on Instagram at any time, which stops any automated processing tied to that identifier.</li>
                <li><strong>Direct request:</strong> users may email <a href="mailto:hola@joanpaneque.com">hola@joanpaneque.com</a> to request deletion of any technical records.</li>
                <li><strong>Meta settings:</strong> users can manage interactions with external apps under &ldquo;Settings and privacy &gt; Apps and websites&rdquo; in their Instagram/Facebook account.</li>
            </ul>

            <h2>6. Third parties</h2>
            <p>
                We do not sell or share data with third parties. Data flows only between the Application&rsquo;s servers and Meta&rsquo;s official infrastructure.
            </p>
        </article>
    </div>
</body>
</html>
