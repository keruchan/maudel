<?php
/**
 * ============================================================
 * File     : includes/sentiment.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Lightweight, transparent lexicon-based text mining for youth
 *            feedback/suggestions — tokenization, stopword filtering, and
 *            sentiment scoring. Filipino (Tagalog) + English, since real
 *            youth input in this system is naturally code-switched.
 *
 * Deliberately NOT a trained ML/NLP model — a fixed, readable word list you
 * can inspect and extend, matching this codebase's existing preference for
 * transparent statistics over opaque black boxes (see sked_linear_regression()
 * in includes/predictive.php, which made the same call over ARIMA/ML).
 * A lexicon scorer is auditable: an SK official can ask "why is this
 * negative?" and get a real answer, which is the point for a government tool.
 * ============================================================ */

/** Function words to drop from the word cloud (they'd otherwise dominate it). */
function sked_stopwords(): array
{
    static $words = null;
    if ($words !== null) { return $words; }
    $words = array_flip([
        // Tagalog/Filipino function words
        'ang','mga','ng','sa','na','ay','at','ito','iyon','yun','yung','dito','doon','diyan',
        'ako','ko','mo','ka','ikaw','siya','niya','kami','namin','tayo','natin','kayo','ninyo',
        'nila','nya','sila','akin','iyo','kanya','amin','atin','inyo','kanila',
        'po','opo','ho','naman','lang','din','rin','pa','ba','kasi','kung','kapag','pag',
        'para','dahil','upang','habang','pero','ngunit','subalit','o','kahit','maging',
        'may','mayroon','wala','walang','hindi','oo','huwag','nang','noong','ngayon',
        'lahat','bawat','ilan','marami','konti','kaunti','sobra','masyado','talaga','naging',
        'nasa','galing','patungo','tungkol','tulad','gaya','katulad','sana','mas','medyo',
        'namin','atin','kanina','bukas','kahapon','araw','buwan','taon',
        'ito ay','yan','iyan','ganito','ganyan','ganoon','dyan',
        // English function words
        'the','a','an','and','or','but','is','are','was','were','be','been','being',
        'to','of','in','on','for','with','as','by','at','from','that','this','these','those',
        'it','its','i','you','he','she','we','they','them','their','our','your','his','her',
        'not','no','so','if','then','than','too','very','just','can','will','would','should',
        'have','has','had','do','does','did','my','me','us','am','also','about','into','more',
        'all','some','any','each','every','other','such','only','own',
    ]);
    return $words;
}

/**
 * Split free text into lowercase word tokens, dropping punctuation, numbers,
 * stopwords, and anything shorter than 3 letters.
 *
 * @return string[]
 */
function sked_tokenize_text(string $text, bool $dropStopwords = true): array
{
    $text = mb_strtolower(trim($text));
    if ($text === '') { return []; }
    // Keep letters (incl. common PH-name diacritics) and spaces only.
    $clean = preg_replace('/[^\p{L}\s]+/u', ' ', $text);
    $parts = preg_split('/\s+/u', trim((string) $clean));
    $stop = sked_stopwords();
    $out = [];
    foreach ((array) $parts as $w) {
        if ($w === '' || mb_strlen($w) < 3) { continue; }
        if ($dropStopwords && isset($stop[$w])) { continue; }
        $out[] = $w;
    }
    return $out;
}

/**
 * Positive/negative word lexicon for youth-governance-flavored feedback,
 * Filipino + English. Not exhaustive — a starting set an SK/PPSK/DILG user
 * can extend by adding entries here as real feedback surfaces new words.
 */
function sked_sentiment_lexicon(): array
{
    return [
        // Single words only — the tokenizer checks one word at a time.
        // Negated phrases like "hindi maganda" are handled generically by
        // sked_negation_markers() flipping the following positive word,
        // not by listing the phrase here.
        'positive' => [
            // Filipino
            'maganda','magandang','mabuti','mabuting','maayos','ayos','masaya','nakakatuwa',
            'nakakatulong','matulungin','sulit','husay','mahusay','magaling',
            'salamat','nagpapasalamat','nakakatuwang','maasahan','madali',
            'komportable','komportableng','organisado','panalo','galing','solid','pinagpala',
            'proud','saya','masigla','maligaya',
            // English
            'good','great','excellent','helpful','amazing','love','loved','wonderful',
            'awesome','fantastic','appreciate','appreciated','thank','thanks','best',
            'nice','happy','glad','impressive','smooth','easy','useful','beneficial',
            'proud','satisfied','enjoyed','enjoy','organized','rewarding','inspiring',
            'effective','improved','improvement','engaging','inclusive','empowering',
        ],
        'negative' => [
            // Filipino
            'masama','masamang','mahirap','hirap','kulang','kakulangan','reklamo','problema',
            'nakakadismaya','nakakabagot','nakakainis','nakakapagod','sayang',
            'ayaw','pangit','delay','naantala','nabigo','nasira','sablay','abala','gulo',
            'nakakastress','nakakahiya','magulo','kupad','nakaka-frustrate',
            // English
            'bad','poor','terrible','awful','disappointing','disappointed','lacking',
            'confusing','slow','late','delayed','problem','issue','complaint',
            'boring','waste','unorganized','disorganized','unclear','difficult',
            'frustrating','frustrated','worse','worst','unfair','ignored','neglected',
        ],
    ];
}

/** Negation markers that flip a following positive word to negative. */
function sked_negation_markers(): array
{
    return array_flip(['hindi', 'wala', 'walang', 'not', 'no', 'never', 'huwag', "wasn't", "isn't"]);
}

/**
 * Score one piece of free text for sentiment.
 *
 * Simplification (documented, not a bug): a negation word immediately before
 * a positive word flips it negative ("hindi maganda" = "not good"). A
 * negation before a NEGATIVE word cancels it to neutral rather than flipping
 * it positive ("walang problema" vs "walang tulong" point opposite ways in
 * real Filipino, so guessing a flip would be wrong as often as right —
 * canceling is the safer call).
 *
 * @return array{score:float,label:string,pos:int,neg:int}
 */
function sked_sentiment_score(string $text): array
{
    $lexicon = sked_sentiment_lexicon();
    $positive = array_flip($lexicon['positive']);
    $negative = array_flip($lexicon['negative']);
    $negators = sked_negation_markers();

    $words = sked_tokenize_text($text, false);
    $pos = 0;
    $neg = 0;
    foreach ($words as $i => $w) {
        $precededByNegation = $i > 0 && isset($negators[$words[$i - 1]]);
        if (isset($positive[$w])) {
            if ($precededByNegation) { $neg++; } else { $pos++; }
        } elseif (isset($negative[$w])) {
            if (!$precededByNegation) { $neg++; }
            // else: negated negative -> treated as neutral, see docblock.
        }
    }

    $total = $pos + $neg;
    $score = $total > 0 ? round(($pos - $neg) / $total, 3) : 0.0;
    $label = $score > 0.15 ? 'positive' : ($score < -0.15 ? 'negative' : 'neutral');

    return ['score' => $score, 'label' => $label, 'pos' => $pos, 'neg' => $neg];
}
