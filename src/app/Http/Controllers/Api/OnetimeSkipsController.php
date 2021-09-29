<?php

namespace App\Http\Controllers\Api;

use App\Models\OnetimeSkip;
use App\Models\SsidTrigger;
use App\Models\TimeTrigger;
use App\Http\Requests\OnetimeSkipRequest;

class OnetimeSkipsController extends BaseApiController
{
  public function index(OnetimeSkipRequest $request)
  {
    $this->checkReadableUser($request);

    return [
      'skipable_count' =>
        OnetimeSkip::where('target_type', $request->target_type)
          ->where('target_id', $request->target_id)
          ->whereNull('deleted_at')
          ->count(),
      'skiped_count' =>
        OnetimeSkip::where('target_type', $request->target_type)
          ->where('target_id', $request->target_id)
          ->whereNotNull('deleted_at')
          ->count()
    ];
  }

  public function store(OnetimeSkipRequest $request)
  {
    $onetimeSkip = OnetimeSkip::create([
      'target_id' => $request->target_id,
      'target_type' => $request->target_type
    ]);
    return ['result', $onetimeSkip];
  }

  public function destroy(OnetimeSkipRequest $request)
  {
    $this->checkReadableUser($request);
    $onetime = OnetimeSkip::where('target_type', $request->target_type)
      ->where('target_id', $request->target_id)
      ->whereNull('deleted_at')
      ->orderBy('id', 'asc')
      ->first();
    if ($onetime == null) {
      return ['result', false];
    }
    return ['result', $onetime->delete()];
  }

  private function checkReadableUser(OnetimeSkipRequest $request)
  {
    $target_id = $request->target_id;
    $target_type = $request->target_type;

    if ($target_type == 'time') {
      $trigger = TimeTrigger::find($target_id);
      $this->checkExecutableUser(
        $trigger->target_type,
        $trigger->target_id,
        $request->user(),
        'select'
      );
    } elseif ($target_type == 'ssid') {
      $trigger = SsidTrigger::find($target_id);
      $this->checkExecutableUser(
        $trigger->target_type,
        $trigger->target_id,
        $request->user(),
        'select'
      );
    }
  }
}
