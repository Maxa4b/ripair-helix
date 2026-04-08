<?php

namespace App\Services\Prospecting;

use App\Exceptions\ConcurrentWriteException;
use App\Models\HelixUser;
use App\Models\Prospecting\Company;
use App\Models\Prospecting\CompanyContactHistory;
use Illuminate\Support\Facades\DB;

class CompanyMutationService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function mutate(Company $company, array $attributes, ?HelixUser $actor, string $source = 'ui', ?string $changeNote = null, ?int $expectedVersion = null): Company
    {
        /** @var Company $updated */
        $updated = DB::transaction(function () use ($company, $attributes, $actor, $source, $changeNote, $expectedVersion): Company {
            /** @var Company|null $locked */
            $locked = Company::query()->whereKey($company->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Company) {
                abort(404, 'Entreprise introuvable.');
            }

            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw new ConcurrentWriteException('Version mismatch.');
            }

            $trackedBefore = [
                'contact_status' => $locked->contact_status,
                'contact_owner' => $locked->contact_owner,
                'notes' => $locked->notes,
            ];

            $locked->fill($attributes);

            if (array_key_exists('contact_status', $attributes)) {
                if ($trackedBefore['contact_status'] === 'non_contacte' && $locked->contact_status !== 'non_contacte' && $locked->first_contact_at === null) {
                    $locked->first_contact_at = now();
                }

                if ($trackedBefore['contact_status'] !== $locked->contact_status) {
                    $locked->last_contact_at = now();
                }
            }

            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $trackedAfter = [
                'contact_status' => $locked->contact_status,
                'contact_owner' => $locked->contact_owner,
                'notes' => $locked->notes,
            ];

            if ($trackedBefore !== $trackedAfter) {
                CompanyContactHistory::query()->create([
                    'company_id' => $locked->id,
                    'previous_status' => $trackedBefore['contact_status'],
                    'new_status' => $trackedAfter['contact_status'],
                    'previous_owner' => $trackedBefore['contact_owner'],
                    'new_owner' => $trackedAfter['contact_owner'],
                    'previous_notes' => $trackedBefore['notes'],
                    'new_notes' => $trackedAfter['notes'],
                    'source' => $source,
                    'change_note' => $changeNote,
                    'changed_by' => $actor?->id,
                    'changed_by_name' => $actor?->full_name,
                    'changed_at' => now(),
                ]);
            }

            return $locked->fresh(['history']) ?? $locked;
        });

        return $updated;
    }
}
