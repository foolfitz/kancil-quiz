<?php

// 壓力測試期間的背景負載（docs/SPEC.md M1 驗收 7）：持續把音檔轉檔存成 Media，
// 並把工作放進 database 佇列，讓佇列與學生作答搶同一個 SQLite 的寫入權。
// 另外開一個 php artisan queue:work 處理這些工作。
//
//   php tests/Load/background.php [秒數]

use App\Media\MediaProcessor;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$seconds = (int) ($argv[1] ?? 60);
$teacher = User::where('email', 'teacher@example.com')->firstOrFail();
$processor = app(MediaProcessor::class);

// 2 秒、440 Hz 的 WAV
$rate = 22050;
$samples = '';
for ($i = 0; $i < $rate * 2; $i++) {
    $samples .= pack('v', (int) (sin(2 * M_PI * 440 * $i / $rate) * 8000) & 0xFFFF);
}
$wav = tempnam(sys_get_temp_dir(), 'kq-load-').'.wav';
file_put_contents($wav, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '
    .pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($samples)).$samples);

$deadline = microtime(true) + $seconds;
$converted = 0;
$jobs = 0;
while (microtime(true) < $deadline) {
    $processor->audio(new UploadedFile($wav, 'load.wav', 'audio/wav', null, true), $teacher);
    $converted++;
    for ($i = 0; $i < 5; $i++) {
        dispatch(function () {
            usleep(20_000);
        });
        $jobs++;
    }
}
unlink($wav);

echo "轉檔 {$converted} 個音檔，放入佇列 {$jobs} 個工作\n";
