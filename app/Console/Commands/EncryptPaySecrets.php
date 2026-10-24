<?php

namespace App\Console\Commands;

use App\Casts\EncryptedOrPlaintext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptPaySecrets extends Command
{
    protected $signature = 'pay:encrypt-secrets {--dry-run : Show what would be encrypted without writing}';

    protected $description = 'Encrypt legacy plaintext payment credentials in the pays table';

    public function handle(): int
    {
        $fields = ['merchant_id', 'merchant_key', 'merchant_pem'];
        $updated = 0;
        $skipped = 0;

        foreach (DB::table('pays')->orderBy('id')->get() as $row) {
            $changes = [];

            foreach ($fields as $field) {
                $value = $row->{$field} ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                if (EncryptedOrPlaintext::looksEncrypted((string) $value)) {
                    $skipped++;
                    continue;
                }

                $changes[$field] = Crypt::encryptString((string) $value);
            }

            if ($changes === []) {
                continue;
            }

            $updated++;

            if ($this->option('dry-run')) {
                $this->line("Would encrypt pays#{$row->id}: ".implode(', ', array_keys($changes)));
                continue;
            }

            DB::table('pays')->where('id', $row->id)->update($changes);
            $this->line("Encrypted pays#{$row->id}");
        }

        $this->info("Done. rows updated: {$updated}, already encrypted fields skipped: {$skipped}");

        return self::SUCCESS;
    }
}
