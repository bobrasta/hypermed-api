<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'label'            => $this->label,
            'from_name'        => $this->from_name,
            'from_email'       => $this->from_email,
            'username'         => $this->username,
            'imap_host'        => $this->imap_host,
            'imap_port'        => $this->imap_port,
            'imap_encryption'  => $this->imap_encryption,
            'smtp_host'        => $this->smtp_host,
            'smtp_port'        => $this->smtp_port,
            'smtp_encryption'  => $this->smtp_encryption,
            'is_default'       => $this->is_default,
            'is_active'        => $this->is_active,
            'last_synced_at'   => $this->last_synced_at?->toIso8601String(),
            // password intentionally excluded
        ];
    }
}
