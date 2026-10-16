<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StandaloneRelease;
use App\Services\Releases\ReleaseDesk;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * Platform › Standalone releases (docs/STANDALONE_EDITION_PLAN.md §10): one prefilled form to stage
 * a version, the build's progress, then Publish / Retry / Withdraw / Download. Routed in the
 * platform group (edition:saas + admin.platform:all): a 404 on a standalone copy, a 403 for shop users.
 */
class StandaloneReleaseController extends Controller
{
    public function index(Content $content, ReleaseDesk $desk)
    {
        $readiness = $desk->readiness();
        $releases = StandaloneRelease::query()->orderByDesc('id')->limit(30)->get();

        return $content->title('Standalone releases')->description('Build from the deployed commits, prove with a clean install, then publish')
            ->body(view('admin.standalone-releases', [
                'releases' => $releases, 'readiness' => $readiness, 'ready' => $desk->ready($readiness),
                'version' => old('version', $desk->nextVersion()), 'notes' => old('notes', $desk->suggestedNotes()),
                'building' => $releases->contains(fn (StandaloneRelease $r) => in_array($r->status, [StandaloneRelease::QUEUED, StandaloneRelease::BUILDING], true)),
            ]));
    }

    public function stage(Request $request, ReleaseDesk $desk)
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->act(fn () => $desk->stage($data['version'], (string) ($data['notes'] ?? ''), Admin::user()?->id),
            'Build queued', "Version {$data['version']} is waiting for the release worker (every 2 minutes). Refresh to follow it.", true);
    }

    public function publish(int $id, ReleaseDesk $desk)
    {
        $release = StandaloneRelease::query()->findOrFail($id);

        return $this->act(fn () => $desk->publish($release), 'Published', "Version {$release->version} is offered to buyers and the update feed.");
    }

    public function retry(int $id, ReleaseDesk $desk)
    {
        $release = StandaloneRelease::query()->findOrFail($id);

        return $this->act(fn () => $desk->retry($release), 'Queued again', "Version {$release->version} will be built again.");
    }

    public function withdraw(int $id, ReleaseDesk $desk)
    {
        $release = StandaloneRelease::query()->findOrFail($id);

        return $this->act(fn () => $desk->withdraw($release), 'Withdrawn', "Version {$release->version} is no longer offered.");
    }

    public function download(int $id)
    {
        $release = StandaloneRelease::query()->findOrFail($id);
        abort_unless(is_string($release->archive_path) && is_file($release->archive_path), 404);

        return response()->download($release->archive_path, 'budget-pro-standalone-'.$release->version.'.zip',
            ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private']);
    }

    private function act(callable $action, string $title, string $message, bool $keepInput = false)
    {
        $back = redirect(admin_url('standalone-releases'));
        try {
            $action();
        } catch (\DomainException|\RuntimeException $e) {
            admin_error('Not done', $e->getMessage());

            return $keepInput ? $back->withInput() : $back;
        }
        admin_success($title, $message);

        return $back;
    }
}
