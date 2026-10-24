<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor as anyone browsing the app may see them: no email, phone or
 * account status (audit SEC-006). Signed-in users get their own details
 * from UserResource via /user/me.
 */
class PublicDoctorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'role'          => 'doctor',
            'doctorProfile' => new DoctorProfileResource($this->whenLoaded('doctorProfile')),
            'avatarUrl'     => $this->avatar_url,
        ];
    }
}
