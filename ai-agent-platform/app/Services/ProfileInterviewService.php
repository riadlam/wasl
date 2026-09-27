<?php

namespace App\Services;

use App\AI\Providers\FalLlmProvider;
use App\Models\AgentChatMessage;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use App\Models\ChannelProfileInterview;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class ProfileInterviewService
{
    public const MAX_QUESTIONS = 10;

    public function __construct(
        private FalLlmProvider $llm,
        private \App\AI\Runtime\SkAgentClient $skClient,
    ) {}

    /**
     * @return array{needed: bool, interview: ?array<string, mixed>, progress: ?array<string, mixed>}
     */
    public function state(Business $business): array
    {
        $active = $this->active($business);
        $profile = $this->primaryProfile($business);
        $gaps = $profile ? $this->detectGaps($this->profileForGaps($profile), \App\AI\ReplyLanguage::forBusiness($business)) : [];

        if ($active) {
            $active = $this->skipInapplicable($active, $profile ? $this->profileForGaps($profile) : []);

            return [
                'needed' => true,
                'interview' => $this->payload($active),
                'progress' => $this->progress($active),
            ];
        }

        // After a completed interview, identity lives in Supabase — do not re-open from empty status meta.
        $completed = ChannelProfileInterview::query()
            ->where('business_id', $business->id)
            ->where('status', ChannelProfileInterview::STATUS_COMPLETED)
            ->exists();
        if ($completed) {
            return [
                'needed' => false,
                'interview' => null,
                'progress' => null,
            ];
        }

        return [
            'needed' => $gaps !== [],
            'interview' => null,
            'progress' => null,
        ];
    }

    /**
     * @return array{needed: bool, interview: array<string, mixed>, progress: array<string, mixed>, message: array<string, mixed>}
     */
    public function start(Business $business, User $user): array
    {
        $active = $this->active($business);
        if ($active) {
            return [
                'needed' => true,
                'interview' => $this->payload($active),
                'progress' => $this->progress($active),
                'message' => $this->latestPrompt($active),
            ];
        }

        $row = $this->primaryProfile($business);
        if (! $row) {
            abort(422, 'No ready channel profile to complete.');
        }

        $gaps = $this->detectGaps($this->profileForGaps($row), \App\AI\ReplyLanguage::forBusiness($business));
        if ($gaps === []) {
            abort(422, 'This channel profile has no missing DM details.');
        }

        $reply = \App\AI\ReplyLanguage::forBusiness($business);
        $questions = $this->questionsForGaps($this->profileForGaps($row), $gaps, $reply);
        $interview = ChannelProfileInterview::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $row->social_account_id,
            'created_by' => $user->id,
            'status' => ChannelProfileInterview::STATUS_ACTIVE,
            'questions' => $questions,
            'current_index' => 0,
        ]);

        $interview = $this->skipInapplicable($interview, $this->profileForGaps($row));
        if ($interview->status !== ChannelProfileInterview::STATUS_ACTIVE) {
            $message = AgentChatMessage::query()->create([
                'business_id' => $business->id,
                'user_id' => null,
                'role' => 'assistant',
                'content' => 'Profile details are complete. You can keep chatting.',
                'meta' => [
                    'source' => 'profile_interview',
                    'interview_id' => $interview->id,
                    'completed' => true,
                ],
            ]);

            return [
                'needed' => false,
                'interview' => $this->payload($interview),
                'progress' => $this->progress($interview),
                'message' => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => optional($message->created_at)?->toIso8601String(),
                    'meta' => $message->meta,
                ],
            ];
        }

        $liveQuestions = $interview->questions ?? $questions;
        $index = (int) $interview->current_index;
        $first = $liveQuestions[$index] ?? $questions[0];
        $opener = $reply === \App\AI\ReplyLanguage::FRENCH
            ? 'Pour compléter le profil, quelques questions.'
            : 'باش نكمّلو البروفيل، شوية أسئلة.';
        $label = $reply === \App\AI\ReplyLanguage::FRENCH ? 'Question' : 'السؤال';
        $shown = $index + 1;
        $text = $opener."\n\n".$label.' '.$shown.' / '.count($liveQuestions).': '.$first['question'];
        $message = AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $text,
            'meta' => [
                'source' => 'profile_interview',
                'interview_id' => $interview->id,
                'question_index' => $index,
            ],
        ]);

        return [
            'needed' => true,
            'interview' => $this->payload($interview),
            'progress' => $this->progress($interview),
            'message' => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => optional($message->created_at)?->toIso8601String(),
                'meta' => $message->meta,
            ],
        ];
    }

    /**
     * @return array{needed: bool, interview: null, progress: null}
     */
    public function abandon(Business $business): array
    {
        $active = $this->active($business);
        if ($active) {
            $active->update(['status' => ChannelProfileInterview::STATUS_ABANDONED]);
        }

        return $this->state($business->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function record(Business $business, int $questionIndex, string $answer): array
    {
        $answer = trim($answer);
        if ($answer === '') {
            return ['ok' => false, 'error' => 'Answer is empty.'];
        }

        $interview = $this->active($business);
        if (! $interview) {
            return ['ok' => false, 'error' => 'No active profile interview.'];
        }

        $profile = $this->primaryProfile($business);
        $interview = $this->skipInapplicable($interview, $profile ? $this->profileForGaps($profile) : []);
        if ($interview->status !== ChannelProfileInterview::STATUS_ACTIVE) {
            return [
                'ok' => true,
                'completed' => true,
                'current_index' => (int) $interview->current_index,
                'follow_up' => $this->savedLine($interview),
                'progress' => $this->progress($interview),
            ];
        }

        $questions = $interview->questions ?? [];
        $current = (int) $interview->current_index;

        // Stale index after an auto-skip: treat as already saved and return the live question.
        if ($questionIndex !== $current) {
            $prior = $questions[$questionIndex] ?? null;
            if (is_array($prior) && trim((string) ($prior['answer'] ?? '')) !== '') {
                return $this->recordSuccessPayload($interview, false);
            }

            return [
                'ok' => false,
                'error' => 'Wrong question index.',
                'current_index' => $current,
                'current_question' => is_array($questions[$current] ?? null)
                    ? (string) ($questions[$current]['question'] ?? '')
                    : '',
            ];
        }

        if (! isset($questions[$questionIndex]) || ! is_array($questions[$questionIndex])) {
            return ['ok' => false, 'error' => 'Question is missing.'];
        }

        $questions[$questionIndex]['answer'] = $answer;
        $this->mergeAnswer($interview, (string) $questions[$questionIndex]['field_path'], $answer, (string) ($questions[$questionIndex]['gap_label'] ?? ''));

        $next = $questionIndex + 1;
        $done = $next >= count($questions);
        $interview->update([
            'questions' => $questions,
            'current_index' => $done ? $questionIndex : $next,
            'status' => $done ? ChannelProfileInterview::STATUS_COMPLETED : ChannelProfileInterview::STATUS_ACTIVE,
            'completed_at' => $done ? now() : $interview->completed_at,
        ]);
        $interview->refresh();

        if (! $done) {
            $interview = $this->skipInapplicable($interview, $profile ? $this->profileForGaps($profile) : []);
        }

        return $this->recordSuccessPayload($interview, $interview->status === ChannelProfileInterview::STATUS_COMPLETED);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSuccessPayload(ChannelProfileInterview $interview, bool $done): array
    {
        $questions = $interview->questions ?? [];
        $index = (int) $interview->current_index;
        $french = $this->interviewFrench($interview);
        if ($done || $interview->status === ChannelProfileInterview::STATUS_COMPLETED) {
            $followUp = $this->savedLine($interview);
        } else {
            $q = $questions[$index] ?? [];
            $n = $index + 1;
            $total = count($questions);
            $text = (string) ($q['question'] ?? '');
            $followUp = $french
                ? "Question {$n} / {$total} : {$text}"
                : "السؤال {$n} / {$total}: {$text}";
        }

        return [
            'ok' => true,
            'completed' => $done || $interview->status === ChannelProfileInterview::STATUS_COMPLETED,
            'current_index' => $index,
            'follow_up' => $followUp,
            'progress' => $this->progress($interview),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toolState(Business $business): array
    {
        $active = $this->active($business);
        if (! $active) {
            return ['active' => false];
        }

        $profile = $this->primaryProfile($business);
        $active = $this->skipInapplicable($active, $profile ? $this->profileForGaps($profile) : []);
        if ($active->status !== ChannelProfileInterview::STATUS_ACTIVE) {
            return ['active' => false];
        }

        $questions = $active->questions ?? [];
        $index = (int) $active->current_index;
        $current = $questions[$index] ?? null;

        return [
            'active' => true,
            'interview_id' => $active->id,
            'current_index' => $index,
            'total' => count($questions),
            'current_question' => is_array($current) ? ($current['question'] ?? '') : '',
            'field_path' => is_array($current) ? ($current['field_path'] ?? '') : '',
            'remaining' => max(0, count($questions) - $index),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<array{field_path: string, hint: string, gap_label: string}>
     */
    public function detectGaps(array $profile, string $language = 'Darija'): array
    {
        $hint = fn (string $key): string => $this->gapHint($key, $language);
        $gaps = [];
        $add = function (string $path, string $hint, string $label = '') use (&$gaps): void {
            if (count($gaps) >= self::MAX_QUESTIONS) {
                return;
            }
            $gaps[] = [
                'field_path' => $path,
                'hint' => $hint,
                'gap_label' => $label,
            ];
        };

        $ops = is_array($profile['operations'] ?? null) ? $profile['operations'] : [];
        $digital = app(\App\AI\Skills\SkillRegistry::class)->inferOffer($profile) === 'digital';
        if (! $digital && trim((string) ($ops['delivery'] ?? '')) === '') {
            $add('operations.delivery', $hint('delivery'));
        }
        if (! $digital && trim((string) ($ops['returns'] ?? '')) === '') {
            $add('operations.returns', $hint('returns'));
        }
        if (trim((string) ($ops['hours'] ?? '')) === '') {
            $add('operations.hours', $hint('hours'));
        }

        $business = is_array($profile['business'] ?? null) ? $profile['business'] : [];
        $payments = $business['payment_signals'] ?? [];
        if (! is_array($payments) || $payments === []) {
            $add('business.payment_signals', $hint('payments'));
        }

        $catalog = is_array($profile['catalog_signals'] ?? null) ? $profile['catalog_signals'] : [];
        if ($catalog === []) {
            $add('catalog_signals', $hint('catalog'));
        } else {
            $hasPrice = false;
            foreach ($catalog as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $quoted = $row['quoted_prices'] ?? [];
                $hints = $row['price_hints'] ?? [];
                if ((is_array($quoted) && $quoted !== []) || (is_array($hints) && $hints !== [])) {
                    $hasPrice = true;
                    break;
                }
            }
            if (! $hasPrice) {
                $add('catalog_signals.quoted_prices', $hint('prices'));
            }
        }

        $audience = is_array($profile['audience'] ?? null) ? $profile['audience'] : [];
        $faqs = $audience['faqs'] ?? [];
        if (! is_array($faqs) || $faqs === []) {
            $add('audience.faqs', $hint('faqs'));
        }

        $guidance = is_array($profile['reply_guidance'] ?? null) ? $profile['reply_guidance'] : [];
        if (! is_array($guidance['dm_patterns'] ?? null) || $guidance['dm_patterns'] === []) {
            $add('reply_guidance.dm_patterns', $hint('dms'));
        }
        if (! is_array($guidance['escalation_topics'] ?? null) || $guidance['escalation_topics'] === []) {
            $add('reply_guidance.escalation_topics', $hint('escalation'));
        }

        $evidence = is_array($profile['evidence'] ?? null) ? $profile['evidence'] : [];
        foreach ($evidence['gaps'] ?? [] as $gap) {
            if (! is_string($gap) || trim($gap) === '') {
                continue;
            }
            $add('evidence.owner_notes', $hint('gap').' '.trim($gap), trim($gap));
        }

        return $gaps;
    }

    private function active(Business $business): ?ChannelProfileInterview
    {
        return ChannelProfileInterview::query()
            ->where('business_id', $business->id)
            ->where('status', ChannelProfileInterview::STATUS_ACTIVE)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function skipInapplicable(ChannelProfileInterview $interview, array $profile): ChannelProfileInterview
    {
        if (app(\App\AI\Skills\SkillRegistry::class)->inferOffer($profile) !== 'digital') {
            return $interview;
        }

        $questions = $interview->questions ?? [];
        $changed = false;
        foreach ($questions as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (trim((string) ($row['answer'] ?? '')) !== '') {
                continue;
            }
            $path = (string) ($row['field_path'] ?? '');
            if (! in_array($path, ['operations.delivery', 'operations.returns'], true)) {
                continue;
            }
            $french = $this->interviewFrench($interview);
            $answer = $path === 'operations.delivery'
                ? ($french ? 'Pas de livraison physique. Produit numérique.' : 'ماكانش توصيل مادي. منتج رقمي.')
                : ($french ? 'Pas de retours physiques. Accès numérique.' : 'ماكانش إرجاع مادي. الوصول رقمي.');
            $questions[$i]['answer'] = $answer;
            $this->mergeAnswer($interview, $path, $answer, (string) ($row['gap_label'] ?? ''));
            $changed = true;
        }

        $index = (int) $interview->current_index;
        while (isset($questions[$index]) && is_array($questions[$index]) && trim((string) ($questions[$index]['answer'] ?? '')) !== '') {
            $index++;
            $changed = true;
        }

        if (! $changed && $index === (int) $interview->current_index) {
            return $interview;
        }

        $done = $index >= count($questions);
        $interview->update([
            'questions' => $questions,
            'current_index' => $done ? max(0, count($questions) - 1) : $index,
            'status' => $done ? ChannelProfileInterview::STATUS_COMPLETED : $interview->status,
            'completed_at' => $done ? now() : $interview->completed_at,
        ]);

        return $interview->fresh();
    }

    private function interviewFrench(ChannelProfileInterview $interview): bool
    {
        $business = Business::query()->find($interview->business_id);

        return $business && \App\AI\ReplyLanguage::forBusiness($business) === \App\AI\ReplyLanguage::FRENCH;
    }

    private function savedLine(ChannelProfileInterview $interview): string
    {
        return $this->interviewFrench($interview)
            ? 'Les détails du profil sont enregistrés. Vous pouvez continuer à discuter.'
            : 'تم حفظ تفاصيل البروفيل. تقدر تكمل تهدر عادي.';
    }

    private function gapHint(string $key, string $language): string
    {
        $french = \App\AI\ReplyLanguage::normalize($language) === \App\AI\ReplyLanguage::FRENCH;

        return match ($key) {
            'delivery' => $french ? 'Comment fonctionne la livraison et vers quelles zones ?' : 'كيفاش يخدم التوصيل و لوين توصلوا؟',
            'returns' => $french ? 'Politique de retour ou d\'échange' : 'كيفاش الإرجاع ولا التبديل؟',
            'hours' => $french ? 'Horaires de réponse' : 'وقتاش تخدموا ولا تجاوبوا؟',
            'payments' => $french ? 'Moyens de paiement acceptés' : 'واش هي طرق الدفع لي تقبلوا؟',
            'catalog' => $french ? 'Produits ou catégories principales' : 'واش هي المنتجات ولا الفئات الرئيسية؟',
            'prices' => $french ? 'Prix habituels à dire aux clients' : 'واش هي الأسعار لي نقولوها للزبائن؟',
            'faqs' => $french ? 'Une question fréquente et sa réponse' : 'سؤال يتكرر بزاف و الإجابة تاعو؟',
            'dms' => $french ? 'Comment répondre aux messages privés' : 'كيفاش تحب نجاوبوا على الميساجات؟',
            'escalation' => $french ? 'Sujets à transmettre à un humain' : 'مواضيع لازم نطلعوها ليكم مباشرة؟',
            default => $french ? 'Préciser ce point manquant :' : 'وضّح هاد النقطة الناقصة:',
        };
    }

    private function primaryProfile(Business $business): ?AiProfilePerChannel
    {
        $account = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->orderByRaw("case when platform in ('facebook','instagram') then 0 else 1 end")
            ->orderBy('id')
            ->first();

        if (! $account) {
            return null;
        }

        return AiProfilePerChannel::query()
            ->where('business_id', $business->id)
            ->where('social_account_id', $account->id)
            ->where('status', AiProfilePerChannel::STATUS_READY)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array{field_path: string, hint: string, gap_label: string}>  $gaps
     * @return list<array{id: string, field_path: string, question: string, answer: null, gap_label: string}>
     */
    private function questionsForGaps(array $profile, array $gaps, string $language = 'Darija'): array
    {
        $phrased = $this->phraseWithLlm($profile, $gaps, $language);
        $questions = [];
        foreach ($gaps as $index => $gap) {
            $question = $phrased[$gap['field_path']] ?? $gap['hint'];
            $questions[] = [
                'id' => (string) Str::ulid(),
                'field_path' => $gap['field_path'],
                'question' => $question,
                'answer' => null,
                'gap_label' => $gap['gap_label'],
            ];
            if (count($questions) >= self::MAX_QUESTIONS) {
                break;
            }
            unset($index);
        }

        return $questions;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array{field_path: string, hint: string, gap_label: string}>  $gaps
     * @return array<string, string>
     */
    private function phraseWithLlm(array $profile, array $gaps, string $language = 'Darija'): array
    {
        $page = (string) ($profile['channel']['page_name'] ?? $profile['business']['display_name'] ?? '');
        $reply = \App\AI\ReplyLanguage::normalize($language);
        $skill = app(\App\AI\Skills\SkillRegistry::class)->promptFor('interview', null, [
            'reply_language' => $reply,
            'page_name' => $page !== '' ? $page : 'the page',
            'offer_type' => app(\App\AI\Skills\SkillRegistry::class)->inferOffer($profile) ?: 'unknown',
        ]);
        try {
            $response = $this->llm->chat([
                ['role' => 'system', 'content' => trim($skill."\n\nWrite short owner interview questions for missing DM facts. Output JSON only: {\"questions\":[{\"field_path\":\"\",\"question\":\"\"}]}. Use only the given field_path values. Max 10. Do not invent business facts. Write every question in {$reply} only. If the language is Darija, use Arabic script only, never Latin arabizi. Never ask a shipping question for a digital offer.")],
                ['role' => 'user', 'content' => json_encode([
                    'page_name' => $page,
                    'gaps' => $gaps,
                ], JSON_UNESCAPED_UNICODE)],
            ], [], [
                'temperature' => 0.2,
                'max_tokens' => 1200,
                'json_object' => true,
                'timeout' => 45,
            ]);
            $content = $response['choices'][0]['message']['content'] ?? '';
            if (! is_string($content)) {
                return [];
            }
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return [];
            }
            $allowed = array_column($gaps, 'field_path');
            $out = [];
            foreach ($decoded['questions'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $path = (string) ($row['field_path'] ?? '');
                $question = trim((string) ($row['question'] ?? ''));
                if ($question !== '' && in_array($path, $allowed, true) && ! isset($out[$path])) {
                    $out[$path] = $question;
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    private function mergeAnswer(ChannelProfileInterview $interview, string $fieldPath, string $answer, string $gapLabel): void
    {
        $profileRow = AiProfilePerChannel::query()
            ->where('business_id', $interview->business_id)
            ->where('social_account_id', $interview->social_account_id)
            ->first();
        if (! $profileRow) {
            return;
        }

        $allowed = [
            'operations.delivery',
            'operations.returns',
            'operations.hours',
            'business.payment_signals',
            'catalog_signals',
            'catalog_signals.quoted_prices',
            'audience.faqs',
            'reply_guidance.dm_patterns',
            'reply_guidance.escalation_topics',
            'evidence.owner_notes',
        ];
        if (! in_array($fieldPath, $allowed, true)) {
            return;
        }

        $namespace = 'brand';
        if (str_contains($fieldPath, 'faq')) {
            $namespace = 'faqs';
        } elseif (
            str_contains($fieldPath, 'delivery')
            || str_contains($fieldPath, 'returns')
            || str_contains($fieldPath, 'hours')
            || str_contains($fieldPath, 'payment')
        ) {
            $namespace = 'policies';
        } elseif (str_contains($fieldPath, 'dm_patterns') || str_contains($fieldPath, 'escalation')) {
            $namespace = 'tone';
        }

        $business = Business::query()->find($interview->business_id);
        if ($business) {
            $this->skClient->ingestKnowledge(
                $business,
                $namespace,
                'profile_interview',
                $interview->social_account_id.':'.$fieldPath,
                trim($gapLabel !== '' ? "{$gapLabel}\n{$answer}" : "{$fieldPath}: {$answer}"),
                [
                    'social_account_id' => $interview->social_account_id,
                    'field_path' => $fieldPath,
                    'storage' => 'supabase',
                ],
            );
        }

        // Status metadata only — track answered interview fields, not full identity JSON.
        $meta = is_array($profileRow->profile) ? $profileRow->profile : [];
        $meta['storage'] = 'supabase';
        $answers = is_array($meta['interview_answers'] ?? null) ? $meta['interview_answers'] : [];
        $answers[$fieldPath] = $answer;
        $meta['interview_answers'] = $answers;
        $answeredGaps = is_array($meta['interview_answered_gaps'] ?? null) ? $meta['interview_answered_gaps'] : [];
        if ($gapLabel !== '') {
            $answeredGaps[] = $gapLabel;
            $meta['interview_answered_gaps'] = array_values(array_unique($answeredGaps));
        }
        $profileRow->update(['profile' => $meta]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileForGaps(AiProfilePerChannel $row): array
    {
        $meta = is_array($row->profile) ? $row->profile : [];
        if (($meta['storage'] ?? '') === 'supabase') {
            // Vector SoR: treat content as empty so detectGaps still asks DM-critical questions,
            // excluding already-answered interview fields.
            $empty = [
                'storage' => 'supabase',
                'business' => [],
                'channel' => [],
                'catalog_signals' => [],
                'audience' => [],
                'voice' => [],
                'reply_guidance' => [],
                'operations' => [],
                'evidence' => ['gaps' => []],
            ];
            foreach (is_array($meta['interview_answers'] ?? null) ? $meta['interview_answers'] : [] as $path => $answer) {
                if (is_string($path) && $answer !== null && $answer !== '') {
                    data_set($empty, $path, $answer);
                }
            }

            return $empty;
        }

        return $meta;
    }

    /**
     * @return list<string>
     */
    private function appendList(mixed $current, string $answer): array
    {
        $list = is_array($current) ? array_values(array_filter($current, 'is_string')) : [];
        $list[] = $answer;

        return array_values(array_unique($list));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ChannelProfileInterview $interview): array
    {
        return [
            'id' => $interview->id,
            'status' => $interview->status,
            'social_account_id' => $interview->social_account_id,
            'current_index' => (int) $interview->current_index,
            'questions' => $interview->questions,
        ];
    }

    /**
     * @return array{current: int, total: int, label: string}
     */
    private function progress(ChannelProfileInterview $interview): array
    {
        $total = count($interview->questions ?? []);
        $current = min((int) $interview->current_index + 1, max($total, 1));

        return [
            'current' => $current,
            'total' => $total,
            'label' => 'Q '.$current.'/'.$total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function latestPrompt(ChannelProfileInterview $interview): array
    {
        $message = AgentChatMessage::query()
            ->where('business_id', $interview->business_id)
            ->where('meta->interview_id', $interview->id)
            ->latest('id')
            ->first();

        return [
            'id' => $message?->id,
            'role' => $message?->role ?? 'assistant',
            'content' => $message?->content ?? '',
            'created_at' => optional($message?->created_at)?->toIso8601String(),
            'meta' => $message?->meta,
        ];
    }
}
