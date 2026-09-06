<?php

namespace App\Services;

use App\Models\Workshop;
use App\Models\WorkshopAiWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Writing the workshops a manager approved onto the platform.
 *
 * Her agent returns a listing off the web: who is running it, who is
 * speaking, when, where, what it costs. The table was built for workshops
 * somebody typed in, so a few of those fields are new columns; the rest is
 * translation between her vocabulary and ours.
 *
 * There is no Content Queue step here, unlike a tutorial or a quiz. The
 * approval already happened - her review stage is what a manager answered to
 * get the run to ready_for_commit, and approving the same list twice would be
 * asking the same question in two places.
 */
class AiWorkshopCommitService
{
    /**
     * A location that means "anywhere".
     *
     * The student page shows a workshop with no country to everybody, which
     * is right for an online one and wrong for one in a city we failed to
     * read. So this decides deliberately rather than by accident.
     */
    private const ONLINE_HINTS = ['online', 'virtual', 'remote', 'webinar', 'zoom'];

    /**
     * @param array $data the "data" object from her response - {workshops: [...]}
     * @return int how many were written
     */
    public function commit(WorkshopAiWorkflow $run, array $data): int
    {
        $workshops = $data['workshops'] ?? [];

        if ($workshops === []) {
            throw new RuntimeException(
                'This run approved no workshops, so there is nothing to add. '
                . 'It has been left open.'
            );
        }

        $written = DB::transaction(function () use ($run, $workshops) {
            $count = 0;

            foreach ($workshops as $workshop) {
                // The link is her one guaranteed identifier, and a manager
                // running the agent twice on the same topic will be offered
                // the same conferences. Matched on it so a second commit
                // updates rather than duplicates.
                Workshop::updateOrCreate(
                    [
                        'roadmap_version_id' => $run->roadmap_version_id,
                        'url'                => $workshop['link'],
                    ],
                    [
                        'version_node_id' => $run->version_node_id,
                        'title'           => $workshop['title'],
                        'organizer'       => $workshop['organizer'] ?? null,
                        'speaker'         => $workshop['speaker'] ?? null,
                        'scheduled_at'    => $this->startsAt($workshop),
                        // She returns no duration: a listing says when a
                        // thing starts, and usually not how long it runs.
                        'duration_minutes' => null,
                        'location'        => $workshop['location'],
                        'country'         => $this->countryFrom($workshop['location']),
                        'cost_type'       => $workshop['cost_type'] ?? null,
                        'price'           => $workshop['price'] ?? null,
                        'ai_metadata'     => $workshop,
                    ]
                );

                $count++;
            }

            $run->update([
                'status'       => 'committed',
                'committed_at' => now(),
            ]);

            return $count;
        });

        return $written;
    }

    /**
     * Her date, plus her time when the listing gave one.
     *
     * date is a real date; time is free text off a web page - "18:00",
     * "6:00 PM", "18h30". Anything unreadable falls back to the start of the
     * day rather than losing the workshop: a date with the wrong hour is
     * still a workshop somebody can find, and a discarded row is not.
     */
    private function startsAt(array $workshop): Carbon
    {
        $date = Carbon::parse($workshop['date'])->startOfDay();
        $time = trim((string) ($workshop['time'] ?? ''));

        if ($time === '') {
            return $date;
        }

        try {
            $parsed = Carbon::parse($time);

            return $date->setTime($parsed->hour, $parsed->minute);
        } catch (Throwable) {
            return $date;
        }
    }

    /**
     * "Beirut, Lebanon" -> "Lebanon", and online -> null.
     *
     * The same heuristic WorkshopController reads with, kept in step on
     * purpose: a workshop filed under a country the matcher cannot derive
     * from a student's location is a workshop nobody is shown.
     */
    private function countryFrom(string $location): ?string
    {
        $lower = mb_strtolower($location);

        foreach (self::ONLINE_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return null;
            }
        }

        $parts = array_map('trim', explode(',', $location));
        $last  = end($parts);

        return $last === '' ? null : $last;
    }
}
