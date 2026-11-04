<?php

namespace App\Console\Commands;

use App\Models\DiningTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TableLinks extends Command
{
    protected $signature = 'tables:links {--csv= : Simpan ke file CSV di disk local (misalnya links/meja.csv)}';

    protected $description = 'Menampilkan URL pesan (isi QR code) setiap meja';

    public function handle(): int
    {
        $tables = DiningTable::orderBy('number')->get();

        if ($tables->isEmpty()) {
            $this->warn('Belum ada meja. Jalankan: php artisan db:seed --class=DiningTableSeeder');

            return self::SUCCESS;
        }

        $rows = $tables->map(fn (DiningTable $table) => [
            $table->number,
            $table->is_active ? 'aktif' : 'nonaktif',
            $table->orderUrl(),
        ])->all();

        $this->table(['Meja', 'Status', 'URL (isi QR code)'], $rows);

        if ($path = $this->option('csv')) {
            Storage::disk('local')->put($path, $this->toCsv($rows));
            $this->info('Disimpan ke '.Storage::disk('local')->path($path));
        }

        return self::SUCCESS;
    }

    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['meja', 'status', 'url'], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}