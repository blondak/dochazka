<?php
declare(strict_types=1);

pcov\start();
register_shutdown_function(static function (): void {
    pcov\stop();
    $prefix = getenv('PCOV_CATALOG') === '1' ? 'catalog-' : 'lines-';
    $file = tempnam(getenv('ATTENDANCE_COVERAGE_DIR'), $prefix);
    file_put_contents($file, json_encode(pcov\collect(), JSON_THROW_ON_ERROR));
});
