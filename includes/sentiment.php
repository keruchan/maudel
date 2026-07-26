<?php
/**
 * ============================================================
 * File     : includes/sentiment.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Lightweight, transparent lexicon-based text mining for youth
 *            feedback/suggestions — tokenization, stopword filtering, and
 *            weighted sentiment scoring. Filipino (Tagalog) + English, since
 *            real youth input in this system is naturally code-switched.
 *
 * Deliberately NOT a trained ML/NLP model — a fixed, readable word list you
 * can inspect and extend, matching this codebase's existing preference for
 * transparent statistics over opaque black boxes (see sked_linear_regression()
 * in includes/predictive.php, which made the same call over ARIMA/ML).
 * A lexicon scorer is auditable: an SK official can ask "why is this
 * negative?" and get a real answer — sked_sentiment_score() returns the exact
 * matched words and their contributions so the UI can show its working.
 *
 * Scoring depth (beyond a plain word count):
 *   - WEIGHTED terms: "terrible" counts harder than "slow".
 *   - INTENSIFIERS / DIMINISHERS: "sobrang ganda" > "ganda" > "medyo maganda".
 *   - NEGATION over a small window: "hindi masyadong maganda" still flips.
 *   - Negated negatives CANCEL rather than flip (see sked_sentiment_score).
 *   - Score normalized by matched terms, so a long rant and a short one that
 *     are equally negative land in the same place.
 * ============================================================ */

/**
 * Function words to drop from the word cloud. Kept deliberately broad in both
 * languages — anything left in the cloud should be a topic, not grammar.
 *
 * Note the bare-consonant entries ('don', 'isn', 'didn', …): the tokenizer
 * strips apostrophes, so "don't" arrives here as "don" and would otherwise
 * show up as a common "word".
 *
 * Broad praise/filler words may appear here because the sentiment panel already
 * handles tone. Specific issue terms are kept so the cloud still surfaces what
 * youth are asking for or complaining about.
 */
function sked_stopwords(): array
{
    static $words = null;
    if ($words !== null) { return $words; }

    $words = array_flip([
        /* ---------- Filipino / Tagalog ---------- */
        // Markers, articles, linkers
        'ang','ng','nang','mga','sa','na','ay','at','ni','nina','kay','kina','si','sina',
        'ung','yung','yong','iyong','nung','noong','pang','pong','hong',
        // Pronouns
        'ako','akin','aking','ko','ikaw','ka','iyo','iyong','mo','siya','sya','niya','nya',
        'kanya','kaniya','kanyang','kaniyang','kami','amin','aming','namin','tayo','atin',
        'ating','natin','kayo','inyo','inyong','ninyo','nyo','sila','nila','kanila','kanilang',
        'sarili','isa\'t','akong','kong','mong','nyang','niyang','siyang','syang','sakin',
        'saakin','sayong','sayo','saayo','samong','samahan','nating','naming','kaninong',
        // Demonstratives / locatives
        'ito','nito','dito','rito','iyan','yan','niyan','diyan','dyan','riyan','iyon','yon',
        'yun','noon','niyon','doon','roon','ganito','ganyan','ganoon','ganun','ganitong',
        'ganto','ganito','ganyan','ganon','ganun','ganito','ganyan','dun','don','dito',
        'andito','nandito','nandoon','nandun','nariyan','nandiyan',
        // Conjunctions / subordinators
        'o','pero','ngunit','subalit','datapwat','kaya','kayat','dahil','sapagkat','sapagkat',
        'kasi','kung','kapag','pag','pagka','para','upang','habang','samantala','bagaman',
        'bagamat','maliban','kundi','saka','tsaka','bago','pagkatapos','tapos','sabay','sabay-sabay',
        'kaysa','gayundin','gayunman','gayunpaman','subalit','kahit','maski','kahitna','habang',
        'porke','kase','kc','dahilsa',
        // Particles / discourse markers
        'po','opo','ho','oho','naman','lang','lamang','din','rin','daw','raw','pa','ba','nga',
        'eh','ay','ha','oh','uy','ano','kasi','talaga','pala','yata','kaya','muna','nalang',
        'nlang','lamang','man','diba','di ba','di','ehh','ah','uh','uhm','umm','hmm','hehe',
        'haha','hahaha','char','chos','eme','naks','hala','opo',
        // Existentials / negation / affirmation
        'may','mayroon','meron','merong','wala','walang','walan','hindi','di','indi','oo','opo',
        'huwag','wag','ayaw','ewan','siguro','baka','parang','mukhang','tila','marahil','yata',
        'ata','wari','wariy','tila','tila ba',
        // Time
        'ngayon','kanina','mamaya','bukas','kahapon','kagabi','araw','araw-araw','buwan','taon',
        'oras','minuto','sandali','tuwing','palagi','lagi','minsan','madalas','simula','hanggang',
        'noong','dati','agad','kaagad','mula','matapos','ngayong','kaninang','mamayang','bukasing',
        'kahapong','taunang','buwang','linggo','lingguhan','weekly','monthly','daily',
        // Question words
        'sino','saan','kailan','bakit','paano','papaano','alin','ilan','magkano','anong',
        'ano','anu','sinu','san','kelan','bat','bakit','pano','paaano',
        // Quantifiers / degree
        'lahat','bawat','iba','ibang','marami','madami','maraming','konti','kaunti','konting',
        'ilang','sobra','sobrang','masyado','masyadong','medyo','bahagya','halos','pinaka',
        'napaka','higit','lalo','mas','pinakamahusay','karamihan','karaniwang','karaniwan',
        'lahat-lahat','lahatlahat','iilan','kaunting','munti','munting',
        // Prepositional / relational
        'nasa','galing','patungo','tungkol','ukol','tulad','gaya','katulad','kasama','kasabay',
        'ayon','base','bilang','kesa','hinggil','patungkol','mula','labas','loob','harap',
        'likod','gilid','ibabaw','ilalim',
        // Common light verbs / copulas
        'maging','naging','magiging','gusto','ayaw','puwede','pwede','maaari','dapat','kailangan',
        'sana','nawa','ginawa','gawin','gagawin','nagawa','magkaroon','mayroong','ginagawang',
        'ginagawa','gumawa','gagawa','lagyan','ilagay','lagay','bigay','bigyan','binigyan',
        'kuha','kumuha','kunin','sabihin','sabi','sinasabi','nasabi','paki','pakisuyo',
        // Numbers
        'isa','dalawa','tatlo','apat','lima','anim','pito','walo','siyam','sampu','una','huli',
        'ikalawa','ikatlo','ikaapat','ikalima',
        // SKed/local governance boilerplate that otherwise crowds the cloud
        'sk','kk','sked','kabataan','sangguniang','sangguniang kabataan','katipunan','barangay',
        'brgy','siniloan','laguna','calabarzon','youth','profile','profiling','event','events',
        'program','programa','programs','proyekto','project','projects','activity','activities',
        'aktibidad','registration','register','registered','member','members','membership',
        'mungkahi','suhestiyon','komento','puna','feedback','abiso','paalala','anunsyo','balita',
        'iskedyul','schedule','nakatakda','gaganapin','kasalukuyan','tapos','natapos','darating',
        'salamat','nagpapasalamat','pasasalamat','mabuti','mabuting','maganda','magandang',
        'maayos','ayos','masaya','masayang','madali','sulit','mahusay','magaling','galing',
        'madagdagan','dagdag','dagdagan','idagdag','regular','regularly',

        /* ---------- English ---------- */
        'a','an','the','and','or','but','nor','yet','so','if','then','than','because','as',
        'until','while','of','at','by','for','with','about','against','between','into','through',
        'during','before','after','above','below','to','from','up','down','in','out','on','off',
        'over','under','again','further','once','here','there','when','where','why','how','all',
        'any','both','each','few','more','most','other','some','such','no','not','only','own',
        'same','too','very','just','can','will','would','should','could','ought','may','might',
        'must','shall','is','are','was','were','be','been','being','am','have','has','had',
        'having','do','does','did','doing','done','i','me','my','myself','we','us','our','ours',
        'you','your','yours','he','him','his','she','her','hers','it','its','they','them','their',
        'theirs','what','which','who','whom','this','that','these','those','also','however',
        'therefore','thus','hence','still','already','ever','never','always','sometimes','often',
        'usually','maybe','perhaps','really','actually','basically','literally','kind','sort',
        'lot','lots','much','many','one','two','three','four','five','first','last','next',
        'please','pls','plz','hello','hi','hey','sir','maam','mam','madam','admin','official',
        'officials','council','councils','office','officer','officers','staff','system','page',
        'form','forms','field','fields','info','information','details','data','record','records',
        'submit','submitted','submission','fill','filled','update','updated','create','created',
        'click','open','close','view','show','display','list','table','card','cards','dashboard',
        'portal','account','user','users','people','person','community','resident','residents',
        'feedback','suggestion','suggestions','comment','comments','remark','remarks','concern',
        'concerns','request','requests','recommendation','recommendations','notice','announcement',
        'announcements','reminder','reminders','news','status','pending','approved','rejected',
        'confirmed','completed','complete','ongoing','upcoming','active','inactive','draft',
        'published','cancelled','canceled',
        'get','got','make','made','want','need','like','go','going','went','come','came','know',
        'think','say','said','see','saw','look','take','put','give','let','use','using','used',
        'thing','things','way','ways','time','times','day','days','week','month','year','years',
        'today','tomorrow','yesterday','tonight','morning','afternoon','evening','date','schedule',
        'scheduled','every','everyone','everything','someone','something','anything','nothing',
        'somewhere','anywhere','else','etc','via','per','within','without','around','near',
        'plus','minus','another','others','overall','regular','regularly','good','great','nice',
        'happy','glad','thank','thanks','grateful','appreciate','appreciated','okay','fine',
        'better','improved','improvement',
        // apostrophe debris left by punctuation stripping
        'don','doesn','didn','isn','aren','wasn','weren','couldn','shouldn','wouldn','hasn',
        'haven','hadn','won','cannot','ain','let','ive','im','youre','youve','theyre','weve',
        'isnt','dont','cant','wont','thats','whats','heres','theres','doesnt','didnt','couldnt',
        'shouldnt','wouldnt','hasnt','havent','hadnt',
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
 * Weighted positive/negative lexicon for youth-governance feedback,
 * Filipino + English. Weight = intensity, not confidence:
 *   2.0 strong ("terrible", "napakaganda")  1.0 normal ("good")  0.6 mild ("okay").
 *
 * Extend freely as real feedback surfaces new vocabulary — that is the whole
 * advantage of a lexicon over a trained model here.
 *
 * @return array{positive:array<string,float>,negative:array<string,float>}
 */
function sked_sentiment_lexicon(): array
{
    static $lex = null;
    if ($lex !== null) { return $lex; }

    $lex = [
        'positive' => [
            /* --- Filipino: strong --- */
            'napakaganda' => 2.0, 'napakahusay' => 2.0, 'napakabuti' => 2.0, 'kahanga-hanga' => 2.0,
            'pinakamaganda' => 2.0, 'walang kapantay' => 2.0, 'perpekto' => 2.0, 'saludo' => 2.0,
            /* --- Filipino: normal --- */
            'maganda' => 1.0, 'magandang' => 1.0, 'ganda' => 1.0, 'mabuti' => 1.0, 'mabuting' => 1.0,
            'maayos' => 1.0, 'ayos' => 0.8, 'masaya' => 1.0, 'saya' => 1.0, 'masayang' => 1.0,
            'nakakatuwa' => 1.0, 'nakakatuwang' => 1.0, 'nakakatulong' => 1.2, 'matulungin' => 1.2,
            'nakatulong' => 1.2, 'tumulong' => 1.0, 'tulong' => 0.8, 'sulit' => 1.2, 'husay' => 1.2,
            'mahusay' => 1.4, 'magaling' => 1.4, 'galing' => 1.2, 'salamat' => 1.2,
            'nagpapasalamat' => 1.4, 'pasasalamat' => 1.2, 'maasahan' => 1.0, 'mapagkakatiwalaan' => 1.2,
            'madali' => 0.8, 'komportable' => 1.0, 'komportableng' => 1.0, 'organisado' => 1.2,
            'maayos na' => 1.0, 'panalo' => 1.2, 'solid' => 1.0, 'pinagpala' => 1.0, 'masigla' => 1.0,
            'maligaya' => 1.2, 'inspirasyon' => 1.2, 'nakaka-inspire' => 1.2, 'produktibo' => 1.2,
            'epektibo' => 1.2, 'malinis' => 1.0, 'ligtas' => 1.0, 'maalaga' => 1.0, 'magandang-loob' => 1.2,
            'bukas-palad' => 1.2, 'suporta' => 1.0, 'sumusuporta' => 1.0, 'nasiyahan' => 1.2,
            'natuwa' => 1.2, 'nagustuhan' => 1.2, 'naaliw' => 1.0, 'pasado' => 0.8,
            /* --- English: strong --- */
            'excellent' => 2.0, 'outstanding' => 2.0, 'amazing' => 2.0, 'fantastic' => 2.0,
            'wonderful' => 2.0, 'superb' => 2.0, 'perfect' => 2.0, 'exceptional' => 2.0,
            'brilliant' => 2.0, 'love' => 1.6, 'loved' => 1.6, 'best' => 1.6, 'awesome' => 1.8,
            /* --- English: normal --- */
            'good' => 1.0, 'great' => 1.4, 'helpful' => 1.2, 'appreciate' => 1.2,
            'appreciated' => 1.2, 'thank' => 1.0, 'thanks' => 1.0, 'grateful' => 1.4,
            'nice' => 1.0, 'happy' => 1.2, 'glad' => 1.0, 'impressive' => 1.4, 'impressed' => 1.4,
            'smooth' => 1.0, 'easy' => 0.8, 'useful' => 1.2, 'beneficial' => 1.2, 'proud' => 1.2,
            'satisfied' => 1.2, 'satisfying' => 1.2, 'enjoyed' => 1.2, 'enjoy' => 1.0,
            'enjoyable' => 1.2, 'organized' => 1.2, 'rewarding' => 1.2, 'inspiring' => 1.4,
            'effective' => 1.2, 'improved' => 1.0, 'improvement' => 0.8, 'engaging' => 1.2,
            'inclusive' => 1.2, 'empowering' => 1.4, 'supportive' => 1.2, 'welcoming' => 1.2,
            'informative' => 1.0, 'accessible' => 1.0, 'safe' => 1.0, 'fun' => 1.2,
            'worthwhile' => 1.2, 'commend' => 1.2, 'kudos' => 1.4, 'okay' => 0.6, 'fine' => 0.6,
            'better' => 0.8, 'responsive' => 1.2, 'transparent' => 1.2, 'fair' => 1.0,
            'professional' => 1.0, 'punctual' => 1.0, 'consistent' => 0.8, 'reliable' => 1.2,
        ],
        'negative' => [
            /* --- Filipino: strong --- */
            'napakasama' => 2.0, 'napakahirap' => 2.0, 'nakakagalit' => 1.8, 'nakakabwisit' => 1.8,
            'pinakamasama' => 2.0, 'kabiguan' => 1.6, 'iresponsable' => 1.8, 'palpak' => 1.6,
            /* --- Filipino: normal --- */
            'masama' => 1.4, 'masamang' => 1.4, 'mahirap' => 1.0, 'hirap' => 1.0, 'nahirapan' => 1.2,
            'kulang' => 1.2, 'kakulangan' => 1.2, 'kapos' => 1.2, 'reklamo' => 1.4, 'nagrereklamo' => 1.4,
            'problema' => 1.2, 'suliranin' => 1.2, 'nakakadismaya' => 1.6, 'dismayado' => 1.6,
            'nakakabagot' => 1.2, 'boring' => 1.2, 'nakakainis' => 1.4, 'inis' => 1.2,
            'nakakapagod' => 1.0, 'pagod' => 0.8, 'sayang' => 1.2, 'pangit' => 1.4,
            'naantala' => 1.2, 'antala' => 1.2, 'nabigo' => 1.4, 'bigo' => 1.4, 'nasira' => 1.2,
            'sira' => 1.0, 'sablay' => 1.4, 'abala' => 1.0, 'gulo' => 1.2, 'magulo' => 1.4,
            'nakakastress' => 1.4, 'stress' => 1.2, 'nakakahiya' => 1.4, 'kupad' => 1.2,
            'mabagal' => 1.2, 'matagal' => 1.0, 'hindi patas' => 1.6, 'pabaya' => 1.6,
            'napabayaan' => 1.6, 'kawalan' => 1.2, 'kalituhan' => 1.2, 'nalilito' => 1.2,
            'nakakalito' => 1.2, 'mahal' => 0.8, 'kakaunti' => 1.0, 'kulang-kulang' => 1.2,
            'balewala' => 1.4, 'binalewala' => 1.6, 'hindi pinansin' => 1.6, 'walang saysay' => 1.6,
            /* --- English: strong --- */
            'terrible' => 2.0, 'awful' => 2.0, 'horrible' => 2.0, 'worst' => 2.0, 'useless' => 1.8,
            'unacceptable' => 1.8, 'disgraceful' => 2.0, 'appalling' => 2.0, 'hate' => 1.8,
            /* --- English: normal --- */
            'bad' => 1.4, 'poor' => 1.4, 'disappointing' => 1.6, 'disappointed' => 1.6,
            'lacking' => 1.2, 'lack' => 1.2, 'confusing' => 1.2, 'confused' => 1.2,
            'slow' => 1.0, 'late' => 1.0, 'delayed' => 1.2, 'delay' => 1.2, 'problem' => 1.2,
            'problems' => 1.2, 'issue' => 1.0, 'issues' => 1.0, 'complaint' => 1.4,
            'complaints' => 1.4, 'waste' => 1.4, 'wasted' => 1.4, 'unorganized' => 1.4,
            'disorganized' => 1.4, 'unclear' => 1.2, 'difficult' => 1.0, 'hard' => 0.8,
            'frustrating' => 1.6, 'frustrated' => 1.6, 'worse' => 1.2, 'unfair' => 1.6,
            'ignored' => 1.6, 'neglected' => 1.6, 'neglect' => 1.6, 'insufficient' => 1.2,
            'inadequate' => 1.4, 'crowded' => 1.0, 'overcrowded' => 1.2, 'noisy' => 0.8,
            'dirty' => 1.2, 'unsafe' => 1.6, 'expensive' => 1.0, 'costly' => 1.0,
            'cancelled' => 1.0, 'canceled' => 1.0, 'postponed' => 1.0, 'shortage' => 1.4,
            'inconsistent' => 1.2, 'unreliable' => 1.4, 'unresponsive' => 1.4, 'biased' => 1.6,
            'favoritism' => 1.8, 'corrupt' => 2.0, 'corruption' => 2.0, 'nonsense' => 1.6,
            'boring' => 1.2, 'tiring' => 1.0, 'exhausting' => 1.2, 'rushed' => 1.0,
        ],
    ];
    return $lex;
}

/** Words that AMPLIFY the next sentiment term ("sobrang ganda"). */
function sked_sentiment_intensifiers(): array
{
    return [
        'sobra' => 1.6, 'sobrang' => 1.6, 'napaka' => 1.8, 'napakaka' => 1.8, 'talagang' => 1.4,
        'talaga' => 1.3, 'masyado' => 1.4, 'masyadong' => 1.4, 'grabe' => 1.6, 'grabeng' => 1.6,
        'lubos' => 1.5, 'lubhang' => 1.5, 'higit' => 1.3, 'pinaka' => 1.7, 'ubod' => 1.6,
        'very' => 1.5, 'really' => 1.4, 'super' => 1.6, 'extremely' => 1.8, 'highly' => 1.4,
        'totally' => 1.5, 'absolutely' => 1.6, 'incredibly' => 1.7, 'so' => 1.2, 'too' => 1.3,
        'quite' => 1.2, 'truly' => 1.4, 'deeply' => 1.5,
    ];
}

/** Words that SOFTEN the next sentiment term ("medyo maganda"). */
function sked_sentiment_diminishers(): array
{
    return [
        'medyo' => 0.5, 'bahagya' => 0.5, 'bahagyang' => 0.5, 'konti' => 0.6, 'kaunti' => 0.6,
        'parang' => 0.7, 'mukhang' => 0.7, 'tila' => 0.7, 'halos' => 0.7,
        'slightly' => 0.5, 'somewhat' => 0.6, 'kinda' => 0.6, 'kind' => 0.7, 'barely' => 0.4,
        'little' => 0.6, 'bit' => 0.6, 'fairly' => 0.7, 'rather' => 0.7,
    ];
}

/** Negation markers. Applied over a small look-back window, not just adjacent. */
function sked_negation_markers(): array
{
    return array_flip([
        'hindi', 'di', 'wala', 'walang', 'huwag', 'wag', 'ayaw', 'kulang', 'never',
        'not', 'no', 'none', 'nothing', 'without', 'cannot', 'cant', 'dont', 'doesnt',
        'didnt', 'isnt', 'arent', 'wasnt', 'werent', 'wont', 'don', 'isn', 'didn', 'doesn',
    ]);
}

/** How many tokens back a negation still reaches ("hindi masyadong maganda"). */
const SKED_NEGATION_WINDOW = 3;

/**
 * Damping added to the score denominator so a single mild word cannot produce
 * a maximum-strength score. Higher = more evidence needed to reach the extremes.
 */
const SKED_SENTIMENT_SMOOTHING = 0.75;

/**
 * The lexicon sentiment of a single word, or null if it carries none.
 * Used to colour word-cloud entries.
 */
function sked_word_sentiment(string $word): ?string
{
    $lex = sked_sentiment_lexicon();
    $word = mb_strtolower($word);
    if (isset($lex['positive'][$word])) { return 'positive'; }
    if (isset($lex['negative'][$word])) { return 'negative'; }
    return null;
}

/**
 * Score one piece of free text for sentiment.
 *
 * Simplification (documented, not a bug): a negation before a POSITIVE word
 * flips it negative ("hindi maganda" = "not good"). A negation before a
 * NEGATIVE word CANCELS it to neutral rather than flipping it positive —
 * "walang problema" and "walang tulong" point opposite ways in real Filipino,
 * so guessing a flip would be wrong as often as right.
 *
 * @return array{score:float,label:string,pos:int,neg:int,pos_weight:float,neg_weight:float,matches:array}
 */
function sked_sentiment_score(string $text): array
{
    $lexicon = sked_sentiment_lexicon();
    $positive = $lexicon['positive'];
    $negative = $lexicon['negative'];
    $negators = sked_negation_markers();
    $intensifiers = sked_sentiment_intensifiers();
    $diminishers = sked_sentiment_diminishers();

    // Keep stopwords: negators/intensifiers live among them and carry meaning here.
    $words = sked_tokenize_text($text, false);

    $posCount = 0;
    $negCount = 0;
    $posWeight = 0.0;
    $negWeight = 0.0;
    $matches = [];

    foreach ($words as $i => $w) {
        $isPos = isset($positive[$w]);
        $isNeg = isset($negative[$w]);
        if (!$isPos && !$isNeg) { continue; }

        $weight = $isPos ? $positive[$w] : $negative[$w];

        // Modifier immediately before the term (one hop), e.g. "sobrang ganda".
        $modifier = 1.0;
        $modWord = null;
        if ($i > 0) {
            $prev = $words[$i - 1];
            if (isset($intensifiers[$prev])) { $modifier = $intensifiers[$prev]; $modWord = $prev; }
            elseif (isset($diminishers[$prev])) { $modifier = $diminishers[$prev]; $modWord = $prev; }
        }

        // Negation anywhere in the preceding window, so modifiers in between
        // ("hindi masyadong maganda") don't break the link.
        $negated = false;
        $negWord = null;
        for ($back = 1; $back <= SKED_NEGATION_WINDOW && ($i - $back) >= 0; $back++) {
            $cand = $words[$i - $back];
            if (isset($negators[$cand])) { $negated = true; $negWord = $cand; break; }
            // Stop at another sentiment term — that negation belongs to it.
            if (isset($positive[$cand]) || isset($negative[$cand])) { break; }
        }

        $effective = $weight * $modifier;
        $appliedAs = null;

        if ($isPos) {
            if ($negated) { $negWeight += $effective; $negCount++; $appliedAs = 'negative'; }
            else { $posWeight += $effective; $posCount++; $appliedAs = 'positive'; }
        } else { // negative term
            if ($negated) {
                $appliedAs = 'cancelled'; // see docblock
            } else {
                $negWeight += $effective; $negCount++; $appliedAs = 'negative';
            }
        }

        $matches[] = [
            'word' => $w,
            'lexicon' => $isPos ? 'positive' : 'negative',
            'applied' => $appliedAs,
            'weight' => round($effective, 2),
            'modifier' => $modWord,
            'negated_by' => $negWord,
        ];
    }

    // Smoothed normalization. A plain (pos-neg)/(pos+neg) saturates at ±1 the
    // moment a single word matches, so "ayos lang" would score as hard as a
    // page-long rave. The +SKED_SENTIMENT_SMOOTHING denominator damps thin
    // evidence, so magnitude tracks how strongly the text actually leans.
    $totalWeight = $posWeight + $negWeight;
    $score = $totalWeight > 0
        ? round(($posWeight - $negWeight) / ($totalWeight + SKED_SENTIMENT_SMOOTHING), 3)
        : 0.0;
    $label = $score > 0.15 ? 'positive' : ($score < -0.15 ? 'negative' : 'neutral');

    return [
        'score' => $score,
        'label' => $label,
        'pos' => $posCount,
        'neg' => $negCount,
        'pos_weight' => round($posWeight, 2),
        'neg_weight' => round($negWeight, 2),
        'matches' => $matches,
    ];
}

/** Plain-language explanation of why an entry got its label — powers the UI tooltip. */
function sked_sentiment_explain(array $result): string
{
    $applied = array_values(array_filter($result['matches'] ?? [], static fn ($m) => $m['applied'] !== 'cancelled'));
    if (empty($applied)) {
        return 'No sentiment words matched — counted as neutral.';
    }
    $bits = [];
    foreach (array_slice($applied, 0, 6) as $m) {
        $bit = $m['word'];
        if (!empty($m['negated_by'])) { $bit = $m['negated_by'] . ' ' . $bit; }
        elseif (!empty($m['modifier'])) { $bit = $m['modifier'] . ' ' . $bit; }
        $bits[] = $bit . ' (' . ($m['applied'] === 'positive' ? '+' : '−') . $m['weight'] . ')';
    }
    return 'Matched: ' . implode(', ', $bits);
}
