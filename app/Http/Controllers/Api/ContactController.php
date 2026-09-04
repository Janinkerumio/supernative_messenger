<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $people = User::query()
            ->whereKeyNot($request->user()->id)
            ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', '%'.$request->string('q').'%')
                    ->orWhere('username', 'like', '%'.$request->string('q').'%')
            ))
            ->orderByDesc('is_online')
            ->orderBy('name')
            ->get();

        return UserResource::collection($people);
    }
}
