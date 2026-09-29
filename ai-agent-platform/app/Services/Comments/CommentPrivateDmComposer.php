<?php

namespace App\Services\Comments;

use App\AI\LlmModels\LlmModelCatalog;
use App\AI\Providers\FalLlmProvider;
use App\AI\ReplyLanguage;
use App\Exceptions\InsufficientWalletException;
use App\Exceptions\WalletOwnerMissingException;
use App\Models\AiTaskCharge;
use App\Models\Business;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Services\Agents\BehaviorRulesPrompt;
use App\Services\Wallet\AiTaskBillingService;
use App\Services\Wallet\WalletService;
use Throwable;

/**
 * Compose a short private DM after a public comment (workflow private_dm mode=agent).
 * Grounded in parent post + identity cues + HARD BUSINESS RULES — no invented prices.
 */
class CommentPrivateDmComposer
{
    public function __construct(
        private FalLlmProvider $llm,
        private LlmModelCatalog $models,
        private CommentPostContextResolver $posts,
        private BehaviorRulesPrompt $rules,
        private WalletService $wallets,
        private AiTaskBillingService $billing,
    ) {}

    public function compose(Business $business, Message $inbound, ?SocialAccount $account): string
    {
        $business->loadMissing('agent', 'agentSettings');
        $language = ReplyLanguage::forBusiness($business);
        $langLine = ReplyLanguage::instruction($language);
        $postId = app(\App\Services\ConversationService::class)->extractPostId(
            is_array($inbound->metadata) ? $inbound->metadata : [],
        );
        $post = $this->posts->forComment($business, $account, $postId);
        $rules = $this->rules->block($business);
        $comment = trim((string) $inbound->text);
        $fallback = $this->fallback($language, $comment);

        $system = <<<TXT
You write ONE short private DM from the shop to a customer who just commented publicly.
{$langLine}
Speak first person as the shop (we / عندنا). Warm, sales-aware, not robotic.
Use the parent post + comment to personalize. If they asked about a product/price/offer on that post, continue that thread in DM and ask ONE clear next question (which pack / quantity / game ID / etc.).
Do NOT invent prices, stock, discounts, or shipping. If price unknown, ask which offer they mean then next step.
HARD BUSINESS RULES (Should / Must not) override style — never violate MUST NOT.
Return plain DM text only (no JSON, no hashtags dump, no "as an AI").
Max ~280 characters.
TXT;
        if ($rules !== '') {
            $system .= "\n\n".$rules;
        }

        $user = json_encode([
            'parent_post' => $post['block'] ?? '(unknown post)',
            'customer_comment' => $comment !== '' ? $comment : '(empty / sticker)',
            'task' => 'Write the private DM now.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';

        $model = $this->models->resolve($business->agentSettings?->llm_model);

        try {
            $this->wallets->authorizeBusinessAi($business, $this->billing->chatAuthorizeDa($model['id']));
        } catch (InsufficientWalletException|WalletOwnerMissingException) {
            return $fallback;
        }

        try {
            $response = $this->llm->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                [],
                [
                    'model' => $model['model'],
                    'temperature' => 0.35,
                    'max_tokens' => 220,
                    'timeout' => 45,
                ],
            );
            $raw = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $cost = (float) ($raw['cost'] ?? $raw['cost_usd'] ?? 0);
            $usage = [
                'prompt_tokens' => (int) ($raw['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($raw['completion_tokens'] ?? 0),
                'cost_usd' => $cost,
                'fal_calls' => 1,
                'calls_with_cost' => $cost > 0 ? 1 : 0,
            ];
            try {
                $this->billing->chargeAiUsage(
                    $business,
                    null,
                    AiTaskCharge::TYPE_AGENT_COMMENT_PRIVATE_DM,
                    (string) $model['id'],
                    $usage,
                    Message::class,
                    $inbound->id,
                    ['surface' => 'comment_private_dm'],
                );
            } catch (InsufficientWalletException|WalletOwnerMissingException $e) {
                report($e);
            }

            $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $text = trim(preg_replace('/^```(?:text)?\s*|\s*```$/u', '', $text) ?? $text);
            if ($text !== '') {
                return mb_substr($text, 0, 500);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $fallback;
    }

    private function fallback(string $language, string $comment): string
    {
        if ($language === ReplyLanguage::FRENCH) {
            return $comment !== ''
                ? 'Merci pour ton commentaire 🙏 On a vu ta question — dis-nous en DM ce que tu veux exactement et on t\'aide.'
                : 'Merci pour ton commentaire 🙏 Écris-nous ici ce dont tu as besoin et on t\'aide.';
        }

        return $comment !== ''
            ? 'شكرا على تعليقك 🙏 شفنا سؤالك — قولي هنا في الخاص واش بالضبط تحب و نعاونوك.'
            : 'شكرا على تعليقك 🙏 بعثلنا هنا واش تحتاج و نعاونوك.';
    }
}
