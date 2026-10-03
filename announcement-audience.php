<?php
declare(strict_types=1);

/** Normalize targeting while keeping older course/year/section requests compatible. */
function normalizeAnnouncementAudience(array $request): array
{
    $type = $request['audienceType'] ?? 'all';
    $value = $request['audienceValue'] ?? '';
    if (!is_string($type) || !is_string($value)) {
        throw new InvalidArgumentException('Choose a valid announcement audience.');
    }
    $type = trim($type);
    $value = trim($value);
    $limits = ['course' => 100, 'year' => 50, 'section' => 60];
    $filters = [];

    if ($type === 'all') {
        $value = '';
    } elseif ($type === 'student') {
        if (!preg_match('/^[1-9][0-9]*$/', $value) || !filter_var($value, FILTER_VALIDATE_INT)) {
            throw new InvalidArgumentException('Choose a registered student before publishing.');
        }
    } elseif ($type === 'group') {
        $requestedFilters = $request['audienceFilters'] ?? [];
        if (!is_array($requestedFilters) || array_diff(array_keys($requestedFilters), array_keys($limits))) {
            throw new InvalidArgumentException('Choose valid course, year, and section filters.');
        }
        foreach ($requestedFilters as $key => $filter) {
            if (!is_string($filter) || mb_strlen(trim($filter)) > $limits[$key]) {
                throw new InvalidArgumentException('Choose valid course, year, and section filters.');
            }
            if (trim($filter) !== '') $filters[$key] = trim($filter);
        }
        if (!$filters) {
            throw new InvalidArgumentException('Select at least one course, year, or section filter.');
        }
        $value = '';
    } elseif (isset($limits[$type])) {
        if ($value === '' || mb_strlen($value) > $limits[$type]) {
            throw new InvalidArgumentException('Choose a valid announcement audience.');
        }
    } else {
        throw new InvalidArgumentException('Choose a valid announcement audience.');
    }

    return ['audienceType' => $type, 'audienceValue' => $value, 'filters' => $filters];
}

/** Shared by the signed-in feed and push delivery; malformed targeting never broadcasts. */
function announcementMatchesStudent(array $announcement, array $student): bool
{
    $type = $announcement['audience_type'] ?? '';
    $value = (string) ($announcement['audience_value'] ?? '');
    $columns = ['course' => 'course', 'year' => 'year_section', 'section' => 'section'];
    if ($type === 'all') return true;
    if ($type === 'student') {
        return preg_match('/^[1-9][0-9]*$/', $value) === 1 && $value === (string) ($student['id'] ?? '');
    }
    if (isset($columns[$type])) {
        return $value !== '' && $value === (string) ($student[$columns[$type]] ?? '');
    }
    if ($type !== 'group') return false;

    $storedFilters = $announcement['audience_filters'] ?? null;
    $filters = is_string($storedFilters) ? json_decode($storedFilters, true) : $storedFilters;
    try {
        $audience = normalizeAnnouncementAudience(['audienceType' => 'group', 'audienceFilters' => $filters]);
    } catch (InvalidArgumentException $error) {
        return false;
    }
    foreach ($audience['filters'] as $key => $filter) {
        if ($filter !== (string) ($student[$columns[$key]] ?? '')) return false;
    }
    return true;
}
