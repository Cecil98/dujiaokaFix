<?php

namespace App\Console\Commands;

use App\Casts\EncryptedOrPlaintext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DecryptPaySecrets extends Command
{
    protected $signature = 'pay:decrypt-secrets {--dry-run : Show what would be decrypted without writing}';

    protected $description = 'Decrypt payment credentials before rolling back to code that does not support encryption';

    public function handle(): int
    {
        $fields = ['merchant_id', 'merchant_key', 'merchant_pem'];
        $updated = 0;

        foreach (DB::table('pays')->orderBy('id')->get() as $row) {
            $changes = [];

            foreach ($fields as $field) {
                $value = $row->{$field} ?? null;

                if ($value === null || $value === '' || ! EncryptedOrPlaintext::looksEncrypted((string) $value)) {
                    continue;
                }

                $changes[$field] = Crypt::decryptString((string) $value);
            }

            if ($changes === []) {
                continue;
            }

            $updated++;

            if ($this->option('dry-run')) {
                $this->line("Would decrypt pays#{$row->id}: ".implode(', ', array_keys($changes)));
                continue;
            }

            DB::table('pays')->where('id', $row->id)->update($changes);
            $this->line("Decrypted pays#{$row->id}");
        }

        $this->info("Done. rows updated: {$updated}");

        return self::SUCCESS;
    }
}
