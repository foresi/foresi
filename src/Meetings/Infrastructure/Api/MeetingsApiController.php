<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api;

use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingSearch;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Domain\Id;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Meetings read by a personal API token with the meetings:read scope: the
 * series, the meetings found by series, attendee, days, linked record and text,
 * and one meeting with its minutes. Only what the person sees (MeetingVisibility);
 * what the use cases refuse is answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class MeetingsApiController
{
    public const string READ_SCOPE = 'meetings:read';
    public const string SERIES = 'api_v1_meetings_series';
    public const string ONE_SERIES = 'api_v1_meetings_one_series';
    public const string MEETINGS = 'api_v1_meetings_meetings';
    public const string MEETING = 'api_v1_meetings_meeting';

    public function __construct(private ScopeGuard $scope, private SeriesReads $series, private MeetingReads $meetings)
    {
    }

    #[Route('/api/v1/meetings/series', name: self::SERIES, methods: ['GET'])]
    #[OA\Tag(name: 'Meetings')]
    #[OA\Parameter(name: 'archived', description: 'true includes the archived series.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The series you see, by name.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MeetingSeries'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the meetings:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function series(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map(MeetingsJson::series(...), $this->series->views(\in_array($request->query->getString('archived'), ['1', 'true'], true)))]);
    }

    #[Route('/api/v1/meetings/series/{seriesId}', name: self::ONE_SERIES, requirements: ['seriesId' => Requirement::UUID], methods: ['GET'])]
    #[OA\Tag(name: 'Meetings')]
    #[OA\Response(response: 200, description: 'The series.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MeetingSeries')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the meetings:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No series with this id that you see.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function oneSeries(string $seriesId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MeetingsJson::series($this->series->view($seriesId))]);
    }

    #[Route('/api/v1/meetings', name: self::MEETINGS, methods: ['GET'])]
    #[OA\Tag(name: 'Meetings')]
    #[OA\Parameter(name: 'q', description: 'Finds the meetings whose title or minutes hold this text, without regard to case.', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'series_id', description: 'Only the meetings of this series.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'attendee_id', description: 'Only the meetings this person of the firm attends.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'from', description: 'Only the meetings held on this day or after, YYYY-MM-DD.', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'to', description: 'Only the meetings held on this day or before, YYYY-MM-DD.', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'link_kind', description: 'With link_id, only the meetings about this record: project or contact.', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'link_id', description: 'The id of the record of link_kind.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'trashed', description: 'true lists the meetings in the trash instead.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The meetings you see, newest first, without their minutes.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Meeting'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A parameter is malformed.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the meetings:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function meetings(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $query = $request->query;
        $search = self::search(
            $query->getString('q'),
            $query->getString('series_id'),
            $query->getString('attendee_id'),
            $query->getString('from'),
            $query->getString('to'),
            $query->getString('link_kind'),
            $query->getString('link_id'),
            \in_array($query->getString('trashed'), ['1', 'true'], true),
        );

        return new JsonResponse(['data' => array_map(fn (Meeting $meeting): array => MeetingsJson::row($this->meetings->viewOf($meeting)), $this->meetings->search($search))]);
    }

    #[Route('/api/v1/meetings/{meetingId}', name: self::MEETING, requirements: ['meetingId' => Requirement::UUID], methods: ['GET'])]
    #[OA\Tag(name: 'Meetings')]
    #[OA\Response(response: 200, description: 'The meeting with its minutes in Markdown.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Meeting')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the meetings:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No meeting with this id that you see.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function meeting(string $meetingId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MeetingsJson::meeting($this->meetings->view($meetingId))]);
    }

    /**
     * A search as REST and MCP read their arguments, an empty one being none.
     *
     * @throws MeetingsRefused for a malformed id or day
     */
    public static function search(string $text, string $seriesId, string $attendeeId, string $from, string $to, string $linkKind, string $linkId, bool $trash, int $limit = 500, ?string $afterId = null): MeetingSearch
    {
        foreach (['seriesId' => $seriesId, 'attendeeId' => $attendeeId, 'linkId' => $linkId] as $field => $id) {
            if ('' !== $id && !Id::isValid($id)) {
                throw MeetingsRefused::invalid('id_invalid', $field, 'An id is a UUID.');
            }
        }
        $day = static function (string $value, string $field): ?\DateTimeImmutable {
            if ('' === $value) {
                return null;
            }
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return false === $day || $day->format('Y-m-d') !== $value ? throw MeetingsRefused::invalid('day_invalid', $field, 'A day is YYYY-MM-DD.') : $day;
        };

        return new MeetingSearch(
            text: mb_substr(trim($text), 0, MeetingSearch::MAX_TEXT),
            seriesId: '' === $seriesId ? null : $seriesId,
            attendeeId: '' === $attendeeId ? null : $attendeeId,
            from: $day($from, 'from'),
            to: $day($to, 'to'),
            linkKind: '' === $linkKind || '' === $linkId ? null : $linkKind,
            linkId: '' === $linkKind || '' === $linkId ? null : $linkId,
            trash: $trash,
            limit: $limit,
            afterId: $afterId,
        );
    }
}
