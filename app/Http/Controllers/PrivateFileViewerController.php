<?php

namespace App\Http\Controllers;

use App\Policies\ApplicationPolicy;
use App\Services\PrivateFileLinks;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateFileViewerController extends Controller
{
    public function show(Request $request, string $id, string $file, ApplicationPolicy $policy, PrivateFileLinks $links): Response
    {
        $request->attributes->set('private-viewer', true);
        $state = $this->state($request, $policy, $id, $file);
        $url = $state['can_view'] ? $links->temporary($request->user(), $id, $state['id'], 'inline') : null;

        return response()->view('applications.file-viewer', compact('id', 'state', 'url'))->withHeaders([
            'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function download(Request $request, string $id, string $file, ApplicationPolicy $policy, PrivateFileLinks $links): Response
    {
        $state = $this->state($request, $policy, $id, $file);
        abort_unless($state['can_download'], 403);

        return redirect()->away($links->temporary($request->user(), $id, $state['id'], 'download'));
    }

    private function state(Request $request, ApplicationPolicy $policy, string $id, string $file): array
    {
        $states = $policy->fileStates($request->user(), $id);
        foreach ($states as $state) {
            if ((string) $state['id'] === $file) {
                return $state;
            }
        }
        abort(403);
    }
}
