<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CustomerReview
 */
class CustomerReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'show_name' => $this->show_name,
            'status' => $this->status,
            'moderated_at' => optional($this->moderated_at)->toISOString(),
            'moderated_by' => $this->moderated_by,
            'admin_note' => $this->admin_note,
            'source_page' => $this->source_page,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}

