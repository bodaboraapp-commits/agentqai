<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiAgent;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Explain what the softphone and campaign pickers will see for a user.
 *
 * The softphone's "Calling From" list comes from /softphone/api/my-numbers,
 * which lists phone_numbers with user_id = the user AND status = 'assigned'
 * (minus numbers attached to AI agents). The campaign picker uses
 * PhoneNumber::provisioned(). If either is unexpectedly empty, this prints the
 * row-level reason instead of guessing.
 */
class PhoneNumberStatus extends Command
{
    protected $signature = 'telephony:phone-status
        {--user= : User id or email (defaults to the first admin)}';

    protected $description = 'Show which phone numbers the softphone and campaign pickers will list for a user, and why others are excluded';

    public function handle(): int
    {
        $userOption = trim((string) ($this->option('user') ?? ''));

        $user = $userOption !== ''
            ? User::query()->where('id', $userOption)->orWhere('email', $userOption)->first()
            : (User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first() ?? User::first());

        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        $this->line('User: '.$user->id.' '.$user->email);

        $numbers = PhoneNumber::query()->orderBy('id')->get();

        if ($numbers->isEmpty()) {
            $this->warn('There are no phone_numbers rows at all. Register/assign one first (telephony:africastalking or Admin numbers).');

            return self::FAILURE;
        }

        $aiAgentNumbers = AiAgent::query()->whereNotNull('phone_number')->pluck('phone_number')->all();

        $this->table(
            ['id', 'number', 'provider', 'status', 'user_id', 'voice', 'ai-agent-number', 'softphone?', 'campaign?'],
            $numbers->map(function (PhoneNumber $number) use ($user, $aiAgentNumbers): array {
                $softphone = (int) $number->user_id === (int) $user->id
                    && $number->status === 'assigned'
                    && ! in_array($number->number, $aiAgentNumbers, true);

                $campaign = $number->provider === 'africastalking'
                    || $number->twilio_sid !== null
                    || $number->provider_number_sid !== null;

                return [
                    $number->id,
                    $number->number,
                    $number->provider,
                    $number->status,
                    $number->user_id,
                    ! empty($number->capabilities['voice']) ? 'yes' : 'no',
                    in_array($number->number, $aiAgentNumbers, true) ? 'yes' : '',
                    $softphone ? 'YES' : 'no',
                    $campaign ? 'YES' : 'no',
                ];
            })->all()
        );

        $softphoneCount = $numbers->filter(fn (PhoneNumber $n): bool => (int) $n->user_id === (int) $user->id
            && $n->status === 'assigned'
            && ! in_array($n->number, $aiAgentNumbers, true))->count();

        $this->info('Softphone "Calling From" will list: '.$softphoneCount.' number(s).');
        $this->info('Campaign create picker will list: '.$numbers->filter(fn (PhoneNumber $n): bool => $n->provider === 'africastalking'
            || $n->twilio_sid !== null
            || $n->provider_number_sid !== null)->count().' number(s).');

        if ($softphoneCount === 0) {
            $this->warn('Softphone list is empty: the number must have user_id='.$user->id.' AND status=assigned AND must not be attached to an AI agent.');
        }

        return self::SUCCESS;
    }
}
