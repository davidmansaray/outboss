<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribe</title>
    <style>
        body { font-family: Georgia, serif; margin: 48px auto; max-width: 32rem; color: #1a1814; }
        p { line-height: 1.5; }
        button { background: #1a1814; color: #fff; border: 0; padding: 10px 16px; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Unsubscribe</h1>
    @if (! empty($confirmed))
        <p>You are unsubscribed. You will not receive further campaign emails at this address.</p>
    @else
        <p>Confirm that you want to stop receiving campaign emails at this address.</p>
        <form method="post">
            <button type="submit">Unsubscribe</button>
        </form>
    @endif
</body>
</html>
