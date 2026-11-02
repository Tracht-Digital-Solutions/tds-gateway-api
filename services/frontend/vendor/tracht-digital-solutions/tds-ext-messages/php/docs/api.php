<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection.
 * `php/tests/MessagesApiDocsTest.php` fails the build if the documented set and
 * the registered set drift apart in either direction.
 */

declare(strict_types=1);

return [
    [
        'method' => 'GET',
        'pattern' => '/messages/summary',
        'summary' => 'Anzahl ungelesener Nachrichten',
        'description' => 'Die `dataEndpoint`-Route des Dashboard-Widgets. Gezählt wird aus '
            . 'Sicht der Gegenseite: der Kunde sieht ungelesene Nachrichten des '
            . 'Inhabers und umgekehrt.',
        'permission' => 'messages:read',
        'responses' => [
            ['status' => 200, 'description' => '`{unread: <Anzahl>}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `messages:read`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/messages',
        'summary' => 'Nachrichtenverlauf lesen',
        'description' => '**Hat einen Seiteneffekt:** der Abruf markiert die Nachrichten der '
            . 'Gegenseite als gelesen — deshalb sinkt der Zähler aus `/messages/summary` '
            . 'unmittelbar danach. Die Sichtbarkeit richtet sich nach der aktiven Firma '
            . '(`X-Act-As-Customer`); ein Admin ohne aktive Firma sieht alle Verläufe.',
        'permission' => 'messages:read',
        'params' => [
            [
                'in' => 'query',
                'name' => 'projectId',
                'type' => 'int',
                'description' => 'Auf ein Projekt einschränken. Nicht-numerische Werte werden ignoriert.',
            ],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{messages: [{id, body, author_type, project_id, created_at, read_at}]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `messages:read`.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/messages',
        'summary' => 'Nachricht schreiben',
        'description' => '`author_type` wird aus der Rolle abgeleitet (`owner` für Admins, '
            . 'sonst `customer`) und ist nicht setzbar. Die Nachricht wird der aktiven '
            . 'Firma zugeordnet; nur ein Admin darf ohne aktive Firma schreiben.',
        'permission' => 'messages:write',
        'params' => [
            ['in' => 'body', 'name' => 'body', 'type' => 'string', 'required' => true, 'description' => '1 bis 10.000 Zeichen.'],
            ['in' => 'body', 'name' => 'projectId', 'type' => 'int', 'description' => 'Nachricht einem Projekt zuordnen.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{id}` der angelegten Nachricht.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `messages:write`.'],
            ['status' => 422, 'description' => 'Text leer oder länger als 10.000 Zeichen — oder keine aktive Firma (Nicht-Admin).'],
        ],
    ],
    [
        'method' => 'PATCH',
        'pattern' => '/messages/{id:[0-9]+}',
        'summary' => 'Nachricht nachträglich ändern',
        'description' => 'Ein Admin darf jede Nachricht ändern, ein Kunde nur die seiner '
            . 'aktiven Firma. Eine nicht sichtbare Id ergibt 404 — es wird nicht '
            . 'zwischen „gibt es nicht" und „gehört jemand anderem" unterschieden.',
        'permission' => 'messages:write',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id der Nachricht.'],
            ['in' => 'body', 'name' => 'body', 'type' => 'string', 'required' => true, 'description' => 'Neuer Text, 1 bis 10.000 Zeichen.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{id}`'],
            ['status' => 400, 'description' => '`body` fehlt vollständig.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `messages:write`.'],
            ['status' => 404, 'description' => 'Nachricht nicht gefunden oder nicht im eigenen Zugriff.'],
            ['status' => 422, 'description' => 'Text leer oder länger als 10.000 Zeichen.'],
        ],
    ],
];
