    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta content="FollowFlow - AI User Factory für Browser-Sessions und Automationen" name="description" />
    <?php /* Diese Datei rendert die PHP-Engine, nicht Blade: Ausgaben brauchen
             `<?= ?>`, `{{ }}` landet hier woertlich im Markup.

             `?v=` ist Absicht: Browser halten Favicons in einem eigenen Cache
             und ignorieren dafuer gewoehnliche Neuladen. Wer das Zeichen
             austauscht, zaehlt den Wert hoch — hier und in `pwa-head`. Im
             Manifest steht er bewusst NICHT: dort prueft PwaFrontendTest den
             Dateinamen ueber `basename()`, eine Query wuerde ihn brechen. */ ?>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('favicon.svg?v=2026-08-01')) ?>" />
    <link rel="alternate icon" type="image/x-icon" href="<?= e(asset('favicon.ico?v=2026-08-01')) ?>" />
    <link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon-180.png?v=2026-08-01')) ?>" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
