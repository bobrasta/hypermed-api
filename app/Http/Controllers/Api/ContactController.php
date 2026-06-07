<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Http\Resources\ContactInteractionResource;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $query = Contact::with(['hospital', 'tags']);

        if ($request->filled('hospital_id')) {
            $query->where('hospital_id', $request->hospital_id);
        }
        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('tag', $request->tag));
        }

        return ContactResource::collection($query->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'       => ['required', 'string'],
            'last_name'        => ['required', 'string'],
            'job_title'        => ['nullable', 'string'],
            'department'       => ['nullable', 'string'],
            'email'            => ['nullable', 'email'],
            'phone'            => ['nullable', 'string'],
            'whatsapp'         => ['nullable', 'string'],
            'hospital_id'      => ['required', 'exists:hospitals,id'],
            'last_contacted_at' => ['nullable', 'date'],
            'next_followup_at' => ['nullable', 'date'],
            'tags'             => ['nullable', 'array'],
            'tags.*'           => ['string'],
        ]);

        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        $contact = Contact::create($data);

        foreach ($tags as $tag) {
            $contact->tags()->create(['tag' => $tag]);
        }

        return response()->json(['data' => new ContactResource($contact->load(['hospital', 'tags']))], 201);
    }

    public function show(Contact $contact)
    {
        $contact->load(['hospital', 'tags', 'interactions']);

        return response()->json(['data' => new ContactResource($contact)]);
    }

    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'first_name'       => ['sometimes', 'string'],
            'last_name'        => ['sometimes', 'string'],
            'job_title'        => ['nullable', 'string'],
            'department'       => ['nullable', 'string'],
            'email'            => ['nullable', 'email'],
            'phone'            => ['nullable', 'string'],
            'whatsapp'         => ['nullable', 'string'],
            'last_contacted_at' => ['nullable', 'date'],
            'next_followup_at' => ['nullable', 'date'],
        ]);

        $contact->update($data);

        return response()->json(['data' => new ContactResource($contact->load(['hospital', 'tags']))]);
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return response()->json(null, 204);
    }

    public function addInteraction(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'type'             => ['required', 'in:call,email,meeting,whatsapp,visit'],
            'summary'          => ['nullable', 'string'],
            'outcome'          => ['nullable', 'string'],
            'next_action'      => ['nullable', 'string'],
            'next_action_date' => ['nullable', 'date'],
        ]);

        $interaction = $contact->interactions()->create($data);
        $contact->update(['last_contacted_at' => now()]);

        return response()->json(['data' => new ContactInteractionResource($interaction)], 201);
    }
}
