<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Prospecting\Company
 */
class ProspectingCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'siren' => $this->siren,
            'siret' => $this->siret,
            'segment' => $this->segment,
            'source' => $this->source,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country' => $this->country,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'google_place_id' => $this->google_place_id,
            'relevance_score' => $this->relevance_score,
            'contact_status' => $this->contact_status,
            'contact_owner' => $this->contact_owner,
            'first_contact_at' => optional($this->first_contact_at)->toIso8601String(),
            'last_contact_at' => optional($this->last_contact_at)->toIso8601String(),
            'notes' => $this->notes,
            'excel_row_id' => $this->excel_row_id,
            'version' => $this->version,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'history' => ProspectingCompanyHistoryResource::collection($this->whenLoaded('history')),
        ];
    }
}
