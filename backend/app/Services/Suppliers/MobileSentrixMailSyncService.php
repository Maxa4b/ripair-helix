<?php

namespace App\Services\Suppliers;

use App\Models\Livreo\SupplierMailEvent;
use App\Models\Livreo\SupplierOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class MobileSentrixMailSyncService
{
    public function __construct(
        private readonly ?string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly string $folder,
        private readonly int $sinceDays,
        private readonly string $fromContains,
        private readonly bool $autoLinkUnnumbered,
    ) {
    }

    public static function make(): self
    {
        $cfg = config('services.mobilesentrix_mail', []);
        return new self(
            $cfg['host'] ?? null,
            (int) ($cfg['port'] ?? 993),
            (string) ($cfg['encryption'] ?? 'ssl'),
            $cfg['username'] ?? null,
            $cfg['password'] ?? null,
            (string) ($cfg['folder'] ?? 'INBOX'),
            (int) ($cfg['since_days'] ?? 30),
            (string) ($cfg['from_contains'] ?? 'mobilesentrix'),
            (bool) ($cfg['auto_link_unnumbered'] ?? false),
        );
    }

    public function enabled(): bool
    {
        return filled($this->host) && filled($this->username) && filled($this->password);
    }

    public function sync(?int $sinceDays = null, int $limit = 60): array
    {
        if (! function_exists('imap_open')) {
            return [
                'ok' => false,
                'status' => 503,
                'error' => 'Extension PHP IMAP absente sur le serveur.',
            ];
        }

        if (! Schema::hasTable('livreo_supplier_orders') || ! Schema::hasTable('livreo_supplier_mail_events')) {
            return [
                'ok' => false,
                'status' => 503,
                'error' => 'Livreo non installé (migrations manquantes).',
            ];
        }

        if (! $this->enabled()) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Configuration IMAP MobileSentrix manquante (MOBILESENTRIX_IMAP_*).',
            ];
        }

        $sinceDays = $sinceDays ?? $this->sinceDays;
        $sinceDays = max(1, min(365, (int) $sinceDays));
        $limit = max(1, min(200, (int) $limit));

        $mailbox = $this->mailboxString();
        $imap = @imap_open($mailbox, (string) $this->username, (string) $this->password, OP_READONLY);
        if (! $imap) {
            return [
                'ok' => false,
                'status' => 502,
                'error' => 'Connexion IMAP impossible (vérifiez hôte/port/login/MDP).',
            ];
        }

        try {
            $since = CarbonImmutable::now()->subDays($sinceDays)->format('d-M-Y');
            // On ne filtre pas côté serveur IMAP sur FROM, car certains expéditeurs passent par Mandrill/SMTP
            // et le champ "from" peut varier. On filtre ensuite côté code en restant limité par "limit".
            $criteria = 'SINCE "'.$since.'"';

            $uids = imap_search($imap, $criteria, SE_UID) ?: [];
            if (! is_array($uids)) {
                $uids = [];
            }

            rsort($uids);
            $uids = array_slice($uids, 0, $limit);

            $stats = [
                'scanned' => count($uids),
                'created' => 0,
                'matched' => 0,
                'matched_existing' => 0,
                'matched_auto' => 0,
                'unmatched' => 0,
                'ambiguous' => 0,
                'skipped_existing' => 0,
                'ignored' => 0,
                'errors' => [],
                'items' => [],
            ];

            foreach ($uids as $uid) {
                try {
                    $overviewArr = imap_fetch_overview($imap, (string) $uid, FT_UID) ?: [];
                    $overview = is_array($overviewArr) && isset($overviewArr[0]) ? $overviewArr[0] : null;
                    if (! $overview) {
                        continue;
                    }

                    $subjectRaw = (string) ($overview->subject ?? '');
                    $fromRaw = (string) ($overview->from ?? '');
                    $dateRaw = (string) ($overview->date ?? '');
                    $messageId = $this->extractMessageId($imap, (int) $uid, (string) ($overview->message_id ?? ''));

                    $existingEvent = SupplierMailEvent::query()->where('message_id', $messageId)->first();
                    if ($existingEvent) {
                        $stats['skipped_existing']++;
                        $reconcile = $this->reconcileExistingEvent($existingEvent);
                        if ($reconcile['matched'] ?? false) {
                            $stats['matched']++;
                            $stats['matched_existing']++;
                            if (($reconcile['mode'] ?? null) === 'auto') {
                                $stats['matched_auto']++;
                            }
                            $stats['items'][] = [
                                'message_id' => $existingEvent->message_id,
                                'received_at' => $existingEvent->received_at?->toIso8601String(),
                                'supplier_order_number' => $existingEvent->supplier_order_number,
                                'carrier' => $existingEvent->carrier,
                                'tracking_number' => $existingEvent->tracking_number,
                                'matched_supplier_order_id' => $existingEvent->matched_supplier_order_id,
                                'matched_order_id' => $existingEvent->matched_order_id,
                                'match_mode' => $reconcile['mode'] ?? 'exact',
                            ];
                        }
                        continue;
                    }

                    $receivedAt = null;
                    if (trim($dateRaw) !== '') {
                        try {
                            $receivedAt = CarbonImmutable::parse($dateRaw);
                        } catch (\Throwable) {
                            $receivedAt = null;
                        }
                    }

                    $subject = $this->decodeMimeHeader($subjectRaw);
                    $from = $this->decodeMimeHeader($fromRaw);

                    if (! $this->looksLikeMobileSentrixShipment($subject, $from)) {
                        $stats['ignored']++;
                        continue;
                    }

                    $body = $this->fetchBestBodyText($imap, (int) $uid);
                    $parsed = $this->parseMobileSentrix($subject, $body);

                    $supplierOrderNumber = $parsed['supplier_order_number'] ?? null;
                    $carrier = $parsed['carrier'] ?? null;
                    $trackingNumber = $parsed['tracking_number'] ?? null;
                    $trackingUrl = $trackingNumber ? $this->trackingUrlFor($carrier, $trackingNumber) : null;

                    // Si rien d'utile n'est extrait, on ignore pour éviter les faux positifs.
                    if (! $supplierOrderNumber && ! $trackingNumber) {
                        $stats['ignored']++;
                        continue;
                    }

                    $event = SupplierMailEvent::create([
                        'supplier' => 'mobilesentrix',
                        'message_id' => $messageId,
                        'received_at' => $receivedAt,
                        'from_email' => $from,
                        'subject' => $subject,
                        'supplier_order_number' => $supplierOrderNumber,
                        'carrier' => $carrier,
                        'tracking_number' => $trackingNumber,
                        'preview' => $this->makePreview($subject, $from, $body),
                    ]);

                    $stats['created']++;

                    $matchedSupplierOrderId = null;
                    $matchedOrderId = null;
                    $matchMode = null;

                    $match = $this->matchAndApply($event);
                    if ($match['matched'] ?? false) {
                        $matchedSupplierOrderId = $match['matched_supplier_order_id'] ?? null;
                        $matchedOrderId = $match['matched_order_id'] ?? null;
                        $matchMode = $match['mode'] ?? null;
                        $stats['matched']++;
                        if ($matchMode === 'auto') {
                            $stats['matched_auto']++;
                        }
                    } elseif (($match['ambiguous'] ?? false) === true) {
                        $stats['ambiguous']++;
                    } else {
                        $stats['unmatched']++;
                    }

                    if ($matchedSupplierOrderId) {
                        $event->update([
                            'matched_supplier_order_id' => $matchedSupplierOrderId,
                            'matched_order_id' => $matchedOrderId,
                        ]);
                    }

                    $stats['items'][] = [
                        'message_id' => $messageId,
                        'received_at' => $receivedAt ? $receivedAt->toIso8601String() : null,
                        'supplier_order_number' => $supplierOrderNumber,
                        'carrier' => $carrier,
                        'tracking_number' => $trackingNumber,
                        'matched_supplier_order_id' => $matchedSupplierOrderId,
                        'matched_order_id' => $matchedOrderId,
                        'match_mode' => $matchMode,
                    ];
                } catch (\Throwable $e) {
                    $stats['errors'][] = $e->getMessage();
                }
            }

            return ['ok' => true] + $stats;
        } finally {
            imap_close($imap);
        }
    }

    private function mailboxString(): string
    {
        $host = trim((string) $this->host);
        $folder = trim((string) $this->folder) ?: 'INBOX';
        $port = (int) $this->port;

        $enc = strtolower(trim((string) $this->encryption));
        $encFlag = '';
        if ($enc === 'ssl') {
            $encFlag = '/ssl';
        } elseif ($enc === 'tls') {
            $encFlag = '/tls';
        }

        return '{'.$host.':'.$port.'/imap'.$encFlag.'}'.$folder;
    }

    private function escapeImap(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\"'], $value);
    }

    private function decodeMimeHeader(string $value): string
    {
        $clean = trim($value);
        if ($clean === '') {
            return '';
        }
        if (! function_exists('imap_mime_header_decode')) {
            return $clean;
        }

        $parts = imap_mime_header_decode($clean) ?: [];
        $out = '';
        foreach ($parts as $p) {
            $charset = strtoupper((string) ($p->charset ?? ''));
            $text = (string) ($p->text ?? '');
            if ($charset !== '' && $charset !== 'DEFAULT' && function_exists('iconv')) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                if (is_string($converted) && $converted !== '') {
                    $text = $converted;
                }
            }
            $out .= $text;
        }
        $out = trim($out);
        return $out !== '' ? $out : $clean;
    }

    private function extractMessageId($imap, int $uid, string $overviewMessageId): string
    {
        $candidate = trim((string) $overviewMessageId);
        if ($candidate !== '') {
            return $candidate;
        }
        $header = @imap_fetchheader($imap, (string) $uid, FT_UID) ?: '';
        if (preg_match('/^Message-ID:\\s*(.+)$/im', $header, $m)) {
            $candidate = trim($m[1]);
        }
        if ($candidate === '') {
            $candidate = 'imap_uid:'.$uid;
        }
        return $candidate;
    }

    private function fetchBestBodyText($imap, int $uid): string
    {
        $structure = @imap_fetchstructure($imap, (string) $uid, FT_UID);
        if (! $structure) {
            $raw = @imap_body($imap, (string) $uid, FT_UID | FT_PEEK);
            return $this->normalizeText($raw ?: '');
        }

        $parts = [];
        $this->flattenParts($structure, '', $parts);

        // Priorité: text/plain puis text/html.
        $preferred = null;
        foreach (['PLAIN', 'HTML'] as $subtype) {
            foreach ($parts as $p) {
                if (($p['type'] ?? '') === 'TEXT' && ($p['subtype'] ?? '') === $subtype) {
                    $preferred = $p;
                    break 2;
                }
            }
        }

        if (! $preferred && ! empty($parts)) {
            $preferred = $parts[0];
        }

        if (! $preferred) {
            $raw = @imap_body($imap, (string) $uid, FT_UID | FT_PEEK);
            return $this->normalizeText($raw ?: '');
        }

        $partNumber = $preferred['number'] ?: '1';
        $raw = @imap_fetchbody($imap, (string) $uid, $partNumber, FT_UID | FT_PEEK) ?: '';
        $decoded = $this->decodePartBody($raw, (int) ($preferred['encoding'] ?? 0));

        if (($preferred['subtype'] ?? '') === 'HTML') {
            $decoded = strip_tags($decoded);
        }

        return $this->normalizeText($decoded);
    }

    private function flattenParts($structure, string $prefix, array &$out): void
    {
        $typeMap = [
            0 => 'TEXT',
            1 => 'MULTIPART',
            2 => 'MESSAGE',
            3 => 'APPLICATION',
            4 => 'AUDIO',
            5 => 'IMAGE',
            6 => 'VIDEO',
            7 => 'OTHER',
        ];

        $type = $typeMap[$structure->type ?? 7] ?? 'OTHER';
        $subtype = strtoupper((string) ($structure->subtype ?? ''));

        if ($type !== 'MULTIPART') {
            $out[] = [
                'number' => $prefix !== '' ? $prefix : '1',
                'type' => $type,
                'subtype' => $subtype,
                'encoding' => (int) ($structure->encoding ?? 0),
            ];
            return;
        }

        $parts = $structure->parts ?? [];
        if (! is_array($parts)) {
            $parts = [];
        }
        foreach ($parts as $idx => $part) {
            $n = (string) ($idx + 1);
            $num = $prefix !== '' ? $prefix.'.'.$n : $n;
            $this->flattenParts($part, $num, $out);
        }
    }

    private function decodePartBody(string $raw, int $encoding): string
    {
        // 3 = quoted-printable, 4 = base64
        if ($encoding === 3) {
            return quoted_printable_decode($raw);
        }
        if ($encoding === 4) {
            $decoded = base64_decode($raw, true);
            return is_string($decoded) ? $decoded : $raw;
        }
        return $raw;
    }

    private function normalizeText(string $value): string
    {
        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\\r\\n\\t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\\s{2,}/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function parseMobileSentrix(string $subject, string $body): array
    {
        $haystack = trim($subject.' '.$body);

        $orderNumber = null;
        foreach ([
            '/Your\\s+Order\\s*#\\s*(\\d{5,})/i',
            '/Order\\s*ID\\s*:?\\s*#\\s*(\\d{5,})/i',
            '/ORDER\\s*ID\\s*#\\s*(\\d{5,})/i',
            '/Order\\s*#\\s*(\\d{5,})/i',
        ] as $re) {
            if (preg_match($re, $haystack, $m)) {
                $orderNumber = $m[1];
                break;
            }
        }

        $carrier = null;
        $carrierMap = [
            'fedex' => 'FedEx',
            'ups' => 'UPS',
            'dhl' => 'DHL',
            'dpd' => 'DPD',
            'gls' => 'GLS',
            'chronopost' => 'Chronopost',
            'colissimo' => 'Colissimo',
            'laposte' => 'La Poste',
        ];
        foreach ($carrierMap as $needle => $label) {
            if (preg_match('/\\b'.preg_quote($needle, '/').'\\b/i', $haystack)) {
                $carrier = $label;
                break;
            }
        }

        $tracking = null;
        $candidates = [];

        // Signaux forts
        foreach ([
            '/Track\\s+Your\\s+Shipment\\s*.*?(\\d{10,22})/i',
            '/Tracking\\s*(?:Number|No\\.?|#)?\\s*[:#]?\\s*(\\d{10,22})/i',
            '/Suivi\\s*(?:de\\s+colis)?\\s*[:#]?\\s*(\\d{10,22})/i',
        ] as $re) {
            if (preg_match($re, $haystack, $m)) {
                $candidates[] = $m[1];
            }
        }

        // Fallback: toutes les suites de chiffres plausibles.
        if (empty($candidates)) {
            if (preg_match_all('/\\b\\d{10,22}\\b/', $haystack, $mm)) {
                $candidates = $mm[0] ?? [];
            }
        }

        $candidates = array_values(array_unique(array_map('trim', $candidates)));
        $candidates = array_filter($candidates, function (string $n) use ($orderNumber): bool {
            if ($orderNumber && $n === $orderNumber) {
                return false;
            }
            // Filtre grossier des numéros FR (tél) détectés par préfixe 33 et longueur 11-12
            if (preg_match('/^33\\d{9}$/', $n)) {
                return false;
            }
            return true;
        });

        if (! empty($candidates)) {
            // Prend le plus long (souvent plus discriminant) puis le 1er.
            usort($candidates, fn (string $a, string $b) => strlen($b) <=> strlen($a));
            $tracking = $candidates[0] ?? null;
        }

        return [
            'supplier_order_number' => $orderNumber,
            'carrier' => $carrier,
            'tracking_number' => $tracking,
        ];
    }

    private function looksLikeMobileSentrixShipment(string $subject, string $from): bool
    {
        $subjectLower = mb_strtolower($subject);
        $fromLower = mb_strtolower($from);
        $needle = mb_strtolower(trim((string) $this->fromContains));

        if ($needle !== '' && (str_contains($subjectLower, $needle) || str_contains($fromLower, $needle))) {
            return true;
        }

        if (preg_match('/Your\\s+Order\\s*#\\s*\\d{5,}/i', $subject)) {
            return true;
        }

        if (str_contains($subjectLower, 'mobilesentrix') || str_contains($fromLower, 'mobilesentrix')) {
            return true;
        }

        return false;
    }

    private function reconcileExistingEvent(SupplierMailEvent $event): bool
    {
        if ($event->matched_supplier_order_id) {
            return ['matched' => false];
        }

        $match = $this->matchAndApply($event);
        if (! ($match['matched'] ?? false)) {
            return ['matched' => false];
        }

        $event->update([
            'matched_supplier_order_id' => $match['matched_supplier_order_id'] ?? null,
            'matched_order_id' => $match['matched_order_id'] ?? null,
        ]);

        return $match;
    }

    private function trackingUrlFor(?string $carrier, string $trackingNumber): string
    {
        $t = urlencode($trackingNumber);
        $c = strtolower(trim((string) $carrier));

        return match (true) {
            str_contains($c, 'fedex') => "https://www.fedex.com/fedextrack/?trknbr={$t}",
            str_contains($c, 'ups') => "https://www.ups.com/track?loc=fr_FR&tracknum={$t}",
            str_contains($c, 'dhl') => "https://www.dhl.com/fr-fr/home/tracking/tracking-express.html?submit=1&tracking-id={$t}",
            str_contains($c, 'dpd') => "https://tracking.dpd.fr/fr/Tracking?numero={$t}",
            str_contains($c, 'gls') => "https://gls-group.com/FR/fr/suivi-colis/?match={$t}",
            default => "https://www.google.com/search?q={$t}",
        };
    }

    private function makePreview(string $subject, string $from, string $body): string
    {
        $base = trim($subject.' — '.$from.' — '.$body);
        return mb_substr($base, 0, 1200);
    }

    private function matchAndApply(SupplierMailEvent $event): array
    {
        $supplierOrderNumber = trim((string) $event->supplier_order_number);
        $trackingNumber = trim((string) $event->tracking_number);

        if ($supplierOrderNumber === '' && $trackingNumber === '') {
            return ['matched' => false];
        }

        // 1) Match strict par n° commande fournisseur (idéal).
        if ($supplierOrderNumber !== '') {
            $matches = SupplierOrder::query()
                ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
                ->where('supplier_order_number', $supplierOrderNumber)
                ->orderByDesc('id')
                ->get();

            if ($matches->count() >= 1) {
                $first = $matches->first();
                foreach ($matches as $supplierOrder) {
                    $this->applyEventToSupplierOrder($supplierOrder, $event, false);
                }

                return [
                    'matched' => true,
                    'mode' => 'exact',
                    'matched_supplier_order_id' => (int) $first->id,
                    'matched_order_id' => (int) $first->order_id,
                    'matched_count' => (int) $matches->count(),
                ];
            }

            // 0 match -> fallback éventuel (auto)
        }

        // 2) Mode "full auto" : si activé, on peut lier un email à une commande fournisseur
        //    sans numéro enregistré, uniquement si l'association est non ambiguë.
        if (! $this->autoLinkUnnumbered) {
            return ['matched' => false];
        }

        if ($supplierOrderNumber === '') {
            return ['matched' => false];
        }

        // On évite de lier un email à une commande si ce n° est déjà utilisé ailleurs.
        $alreadyUsed = SupplierOrder::query()
            ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
            ->where('supplier_order_number', $supplierOrderNumber)
            ->exists();
        if ($alreadyUsed) {
            return ['matched' => false];
        }

        $receivedAt = $event->received_at ?? null;
        $windowStart = $receivedAt ? $receivedAt->subDays(30) : now()->subDays(30);
        $windowEnd = $receivedAt ? $receivedAt->addDays(2) : now()->addDays(2);

        $candidates = SupplierOrder::query()
            ->whereIn('supplier', ['mobilesentrix', 'mobile_sentrix'])
            ->whereNull('supplier_order_number')
            ->whereNull('supplier_tracking_number')
            ->whereIn('status', ['to_order', 'ordered'])
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->orderByDesc('ordered_at')
            ->orderByDesc('created_at')
            ->get();

        if ($candidates->count() === 0) {
            return ['matched' => false];
        }

        // Association prudente : si plusieurs candidats proches, on n'associe pas.
        if ($candidates->count() > 1) {
            $first = $candidates[0];
            $second = $candidates[1];
            $a = $first->ordered_at ?? $first->created_at;
            $b = $second->ordered_at ?? $second->created_at;
            $diffMinutes = abs($a->diffInMinutes($b));
            if ($diffMinutes < 180) {
                return ['matched' => false, 'ambiguous' => true];
            }
        }

        $supplierOrder = $candidates->first();
        $this->applyEventToSupplierOrder($supplierOrder, $event, true);

        return [
            'matched' => true,
            'mode' => 'auto',
            'matched_supplier_order_id' => (int) $supplierOrder->id,
            'matched_order_id' => (int) $supplierOrder->order_id,
        ];
    }

    private function applyEventToSupplierOrder(SupplierOrder $supplierOrder, SupplierMailEvent $event, bool $alsoSetSupplierOrderNumber): void
    {
        $updates = [
            'supplier_carrier' => $event->carrier,
            'supplier_tracking_number' => $event->tracking_number,
            'supplier_tracking_url' => $event->tracking_number ? $this->trackingUrlFor($event->carrier, $event->tracking_number) : null,
            'supplier_shipped_at' => $event->received_at,
            'supplier_email_message_id' => $event->message_id,
            'supplier_email_subject' => $event->subject,
            'supplier_email_from' => $event->from_email,
            'supplier_email_received_at' => $event->received_at,
        ];

        if ($alsoSetSupplierOrderNumber && $supplierOrder->supplier_order_number === null) {
            $n = trim((string) $event->supplier_order_number);
            if ($n !== '') {
                $updates['supplier_order_number'] = $n;
            }
        }

        if ($supplierOrder->status === 'to_order') {
            $updates['status'] = 'ordered';
        }
        if (! $supplierOrder->ordered_at) {
            $updates['ordered_at'] = $event->received_at ?? now();
        }

        $supplierOrder->update($updates);
    }
}
