<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Services\AccessLimits;
use App\Services\RecordAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApplicationDetailController extends Controller
{
    public function show(Request $request, string $id, ApplicationPolicy $policy, RecordAudit $audit): Response
    {
        try {
            $record = $policy->detailRecord($request->user(), $id);
            app(AccessLimits::class)->consume($request->user(), 'details', $id, $record->company_id, $request);
            $audit->record($request->user(), 'application.viewed_detail', $id, $record->company_id, $request);
            $editor = $record->note_updated_by ? User::query()->whereKey($record->note_updated_by)->value('name') : null;
            $files = $policy->fileStates($request->user(), $id);

            return response()->view('applications.detail', compact('record', 'editor', 'files'))->withHeaders([
                'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        } catch (HttpExceptionInterface $exception) {
            abort_if($exception->getStatusCode() === 403, 403, __('applications.detail_denied'));
            throw $exception;
        } catch (Throwable) {
            abort(503, __('applications.detail_unavailable'));
        }
    }

    public function update(Request $request, string $id, ApplicationPolicy $policy, RecordAudit $audit): Response
    {
        try {
            DB::transaction(function () use ($request, $id, $policy, $audit) {
                $record = $policy->detailRecord($request->user(), $id, true);
                $validator = Validator::make($request->only(['status', 'internal_notes', 'edit_version']), [
                    'status' => ['required', 'in:new,viewed,interview,hired,rejected'],
                    'internal_notes' => ['nullable', 'string', 'max:2000'], 'edit_version' => ['required', 'integer', 'min:0'],
                ]);
                abort_if($validator->fails(), 422, __('applications.detail_invalid'));
                $data = $validator->validated();
                abort_if((string) $record->edit_version !== (string) $data['edit_version'], 409, __('applications.detail_conflict'));
                $notes = $data['internal_notes'] ?? null;
                $changes = ['status' => $data['status'], 'internal_notes' => $notes, 'edit_version' => $record->edit_version + 1];
                if ($record->status !== $data['status']) {
                    $audit->record($request->user(), 'application.status_changed', $id, $record->company_id, $request, ['old' => $record->status, 'new' => $data['status']]);
                }
                if ($record->internal_notes !== $notes) {
                    $audit->record($request->user(), 'application.note_updated', $id, $record->company_id, $request);
                    $changes += ['note_updated_by' => $request->user()->id, 'note_updated_role' => $request->user()->fresh()->role->value, 'note_updated_at' => now()];
                }
                $policy->saveDetailRecord($request->user(), $id, $changes);
            });

            return redirect()->route('applications.detail', ['id' => $id]);
        } catch (HttpExceptionInterface $exception) {
            abort_if($exception->getStatusCode() === 403, 403, __('applications.detail_denied'));
            throw $exception;
        } catch (Throwable) {
            abort(503, __('applications.detail_unavailable'));
        }
    }
}
