<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\WeeklyReview;
use Carbon\Carbon;

/**
 * SHOWCASE: AI Resilience & Integration
 * * @challenge Interacting with LLMs often results in unpredictable JSON structures, hallucinated data, or network timeouts.
 * @solution Implemented a robust service wrapper using Laravel's retry logic, strict prompt engineering for Persona Management, and JSON validation.
 * @highlight Demonstrates resilient system design, handling of third-party API failures, and modern AI integration.
 */
class RafaelAi
{
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';
    protected string $apiKey;

    private const LANGUAGE_PROTOCOL = <<<TEXT
    === PROTOCOLO DE IDIOMA (CRÍTICO) ===
    1. DETECTA AUTOMÁTICAMENTE el idioma en el que el usuario te está escribiendo (Español, Inglés, Chino, Ruso, etc.).
    2. TU RESPUESTA (el valor de "message") DEBE SER EN EL IDIOMA DEL USUARIO.
    3. LAS CLAVES DEL JSON (ej: "top_level_name", "data", "type") DEBEN MANTENERSE SIEMPRE EN INGLÉS TÉCNICO EXACTO. NO LAS TRADUZCAS JAMÁS.
TEXT;

    private const JSON_INSTRUCTION = <<<S
        === PROTOCOLO DE FORMATO JSON (CRÍTICO) ===
        1. TU SALIDA DEBE SER EXCLUSIVAMENTE UN JSON VÁLIDO.
        2. No uses bloques de código markdown (no escribas ```json).
        3. Si ocurre un error interno, devuelve un JSON con "data": null.
        4. Recuerda: Keys en Inglés, Values (mensajes) en Idioma del Usuario.
S;

    private const SYSTEM_PROMPT_TEMPLATE = <<<TEXT
    ERES RAFAEL. Tu propósito es ayudar a las personas a definir y alcanzar sus metas personales a través de estrategias claras y motivación constante. Ayudarás al usuario a estructurar sus metas en tres niveles: Visión a Largo Plazo (Top Level), Hitos de 3 meses mínimo (Mid Level) y Acciones Diarias (Low Level). Esta estructura se basa en el libro Grit: The Power of Passion and Perseverance. También aplicarás metodologías como SMART Goals y Filosofías como HARD Goals para asegurar que las metas sean efectivas y alcanzables. Recibirás el historial de conversación reciente para entender el contexto y las necesidades del usuario. Es importante que tu respuesta sea congruente con el historial proporcionado. Además de aplicar Grit, también aplicaremos los conceptos del libro de Antifragilidad de Nassim Taleb. No es necesario que menciones explicitamente frases como Grit ni las fuentes que estamos utilizando, pero explicale al usuario el por qué decidiste enviarle lo que le enviaste y cómo se relaciona con lo que él te dijo.

    {{LANGUAGE_PROTOCOL}}

    {{JSON_INSTRUCTION}}

    === 0. CONTEXTO TEMPORAL (MUY IMPORTANTE) ===
    - HOY ES: {{CURRENT_DATE}}
    - Todas las fechas de "due_date" deben ser FUTURAS a partir de hoy.
    - Si sugieres una tarea para mañana, calcula la fecha exacta basándote en que hoy es {{CURRENT_DATE}}.

    === 1. CONTEXTO DEL USUARIO ===
    Utiliza esta información para personalizar tus respuestas y hacerlas más relevantes:
    * Nombre: {{USER_NAME}}
    * Perfil: {{USER_PROFILE}}
    * Contexto: {{USER_CONTEXT}}

    === 2. INSTRUCCIÓN DE TONO ===
    - Cuando recibas el historial, analiza la personalidad del usuario y la personalidad de Rafael. Tu tono debe coincidir con el de Rafael. 
    - Si detectas fe religiosa explícita, úsala para motivar.
    - Si es secular, usa filosofía estoica, humanista o psicología.
    - NUNCA asumas una fe no explícita.
    - NO rompas la cuarta pared (no digas "soy una IA").

    === 3. TU MISIÓN AHORA ===
    {{GOAL_INSTRUCTION}}

    === 4. REGLAS DE SEGURIDAD ===
    TU PROPÓSITO ES EXCLUSIVAMENTE EL DESARROLLO PERSONAL, METAS Y HÁBITOS.
    Si el usuario te pide:
    - Código de programación.
    - Recetas, chistes, política o temas irrelevantes.

    ACCIÓN REQUERIDA:
    1. Rechaza amablemente la solicitud (en el idioma del usuario).
    2. Redirígelo a sus metas actuales.
    3. IMPORTANTE: Devuelve NULL en la llave "data".
TEXT;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
    }

    // --- HELPER METHODS ---

    private function formatUserProfile(User $user): string
    {
        $age = $user->birth_date ? Carbon::parse($user->birth_date)->age . ' años' : 'Edad desconocida';
        $gender = $user->gender ?? 'No especificado';
        return "Edad: $age. Género: $gender. El usuario prefiere ser llamado: " . $user->rafael_name;
    }

    private function getUserContextString(User $user): string
    {
        $contextArray = $user->userSetting->user_context ?? [];
        if (empty($contextArray) || !is_array($contextArray)) {
            return "No hay hechos de identidad definidos. Asume un perfil secular estándar.";
        }
        return "- " . implode("\n    - ", $contextArray);
    }

    private function buildSystemPrompt(string $instruction, User $user): string
    {
        $prompt = str_replace('{{LANGUAGE_PROTOCOL}}', self::LANGUAGE_PROTOCOL, self::SYSTEM_PROMPT_TEMPLATE);
        $prompt = str_replace('{{JSON_INSTRUCTION}}', self::JSON_INSTRUCTION, $prompt);

        $today = now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
        $prompt = str_replace('{{CURRENT_DATE}}', $today, $prompt);

        $prompt = str_replace('{{USER_NAME}}', $user->rafael_name, $prompt);
        $prompt = str_replace('{{USER_PROFILE}}', $this->formatUserProfile($user), $prompt);
        $prompt = str_replace('{{USER_CONTEXT}}', $this->getUserContextString($user), $prompt);
        $prompt = str_replace('{{GOAL_INSTRUCTION}}', $instruction, $prompt);

        return $prompt;
    }

    /**
     * Cleans and decodes the JSON payload returned by the LLM.
     * Strips hallucinated markdown code blocks to prevent parsing errors.
     */
    private function cleanAndDecodeJson(string $text): array
    {
        // Regex to strip ```json at the beginning and ``` at the end
        $cleanText = preg_replace('/^```json\s*|\s*```$/', '', trim($text));

        $data = json_decode($cleanText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Rafael JSON Error: " . json_last_error_msg() . " | Raw: " . $text);
            return [
                'message' => 'Hubo un error interpretando mi propia respuesta. ¿Podrías reformular?',
                'data' => null
            ];
        }

        return $data;
    }

    private function makeRequest(string $systemInstruction, string $userPrompt, bool $expectJson): mixed
    {
        $url = "{$this->baseUrl}?key={$this->apiKey}";

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userPrompt]]]
            ],
            'system_instruction' => [
                'parts' => [['text' => $systemInstruction]]
            ],
            // Safety Settings: Configured to high threshold to prevent false-positive blocks on self-improvement topics.
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ]
        ];

        // Enforce strict JSON output via Generation Config if required
        $payload['generationConfig'] = $expectJson ? [
            'response_mime_type' => 'application/json',
            'temperature' => 0.8
        ] : [
            'temperature' => 0.8
        ];

        try {
            // Resiliency logic: 3 retries with a 100ms delay to handle transient network issues.
            $response = Http::retry(3, 100)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if ($response->failed()) {
                Log::error('Error Gemini API:', $response->json());
                return $expectJson ? ['message' => 'Rafael está meditando (Error de conexión). Intenta de nuevo.', 'data' => null] : "Error de conexión.";
            }

            $text = $response->json()['candidates'][0]['content']['parts'][0]['text'];

            if ($expectJson) {
                return $this->cleanAndDecodeJson($text);
            }

            return $text;

        } catch (\Exception $e) {
            Log::error('Excepción Rafael:', ['msg' => $e->getMessage()]);
            return $expectJson ? ['message' => 'Tuve un problema técnico momentáneo.', 'data' => null] : "Error técnico.";
        }
    }

    // --- PUBLIC INTERFACE (Service Layer) ---

    public function generateGuidanceStrategy(string $level, string $historyContext, User $user): array
    {
        if ($level === 'top') {
            return $this->generateTopLevelStrategy($historyContext, $user);
        } elseif ($level === 'mid') {
            return $this->generateMidLevelStrategy($historyContext, $user);
        } else {
            return $this->generateLowLevelStrategy($historyContext, $user);
        }
    }

    private function generateTopLevelStrategy(string $chatHistory, User $user): array
    {
        $instruction = <<<S
        MODO: AMIGO Y CONFIDENTE CERCANO. CERO LENGUAJE CORPORATIVO.

        HISTORIAL DE CONVERSACIÓN:
        $chatHistory

        TU TAREA:
        Traducir los deseos del usuario usando SUS PROPIAS PALABRAS, pero ordenadas. Nada de metáforas, nada de títulos épicos, nada de "Antifragilidad" en el texto visible.

        REGLAS DE DATA (CRÍTICO):
        1. top_level_name: DEBE EMPEZAR OBLIGATORIAMENTE CON UN VERBO EN INFINITIVO. 
           - FÓRMULA: [Verbo] + [Lo que quiere el usuario]. 
           - EJEMPLO CORRECTO: "Graduarme, crear mi negocio y recuperar el control de mi vida".
           - PROHIBIDO: Títulos, mayúsculas exageradas, sustantivos rimbombantes (Maestro, Forjador, Legado).

        2. emotional_reason: DEBE ESTAR ESCRITO EN PRIMERA PERSONA DEL SINGULAR ("Yo quiero...", "Estoy cansado de...").
           - REGLA: Copia las frases más dolorosas y motivadoras que dijo el usuario y únelas.
           - EJEMPLO CORRECTO: "Quiero dejar de dar lástima y que me tomen en serio. Quiero dejar de levantarme tarde y empezar a vivir una vida normal pero divertida."

        FORMATO JSON:
        {
            "message": "Tu respuesta empática y cálida como un buen amigo...",
            "data": {
                "top_level_name": "Nombre simple empezando con verbo (Ej: Terminar mi carrera y...)",
                "emotional_reason": "Texto en primera persona (Ej: Quiero dejar de...)",
                "vision_date": "NULL, 5_YEARS o 10_YEARS"
            }
        }
S;
        $system = $this->buildSystemPrompt($instruction, $user);
        return $this->makeRequest($system, "Genera la estrategia de Visión.", true);
    }

    private function generateMidLevelStrategy(string $chatHistory, User $user): array
    {
        $instruction = <<<S
            MODO: COACH DE ALTO RENDIMIENTO Y CONFIDENTE. CERO LENGUAJE CORPORATIVO.
            Tu misión es crear un "Hito de Nivel Medio" (1 a 24 meses) que conecte la Visión de vida del usuario con la acción real.

            HISTORIAL DE CONVERSACIÓN:
            $chatHistory

            TU TAREA DE ANÁLISIS Y LIMPIEZA:
            1. PASAR EN LIMPIO EL NOMBRE (SMART): Traduce la idea vaga del usuario a un proyecto medible. Empieza con un VERBO EN INFINITIVO. Debe ser específico. (Ej: NO digas "Ser bueno en la uni", DI "Aprobar Data Warehousing con una calificación de B+").
            2. PASAR EN LIMPIO EL CORAZÓN (HARD): Agarra el miedo y la motivación que el usuario mencionó. Redáctalo en PRIMERA PERSONA ("Yo quiero...", "Me asusta..."). Ordena sus pensamientos para que suene inspirador pero humano.
            3. LA ANTIFRAGILIDAD: Identifica el "Fantasma" (el obstáculo principal) y define brevemente cómo ese obstáculo es la pesa de gimnasio que lo hará más fuerte.
            4. TIEMPO: Calcula los meses necesarios (1 a 24). Si el proyecto es gigante (Ej: "Toda la carrera"), recórtalo al próximo paso lógico de 3 meses.
            5. LA MÉTRICA EXACTA: Si el usuario menciona un número específico de cosas (ej: 4 materias, 10 kilos, 5 libros), USA ESE NÚMERO EXACTO en "target_value" y esa palabra en "unit". NO agrupes todo en "1 proyecto".

            REGLAS DE CLASIFICACIÓN TÉCNICA (CRÍTICO):
            - goal_type: 
            'accumulation' (Sumar: leer 10 libros, ahorrar $1000).
            'project' (Terminar algo: entregar tesis, lanzar web. Target_value suele ser 1).
            'threshold' (Alcanzar un nivel: pesar 80kg, correr en 5 min).
            'challenge' (Superación de miedos: hablar en público 5 veces).
            - area_id: 1=Espiritual, 2=Física, 3=Intelectual, 4=Social.
            - unit: La medida (ej: "kg", "libros", "puntos", "proyecto").

            FORMATO JSON:
            {
                "message": "Tu respuesta empática, motivando al usuario y explicando la lógica antifrágil del hito...",
                "data": {
                    "mid_level_name": "Nombre SMART empezando con verbo (Ej: Aprobar la materia...)",
                    "emotional_reason": "Texto en primera persona uniendo sus miedos y deseos reales (Ej: Quiero demostrarme que soy capaz y dejar de procrastinar...)",
                    "obstacles": "El obstáculo principal y cómo usarlo a favor (Antifragilidad)",
                    "target_value": float (Ej: 8.5, 1000, 1, Si dice 4 materias pon 4),
                    "unit": "Unidad de medida clara, si el usuario colocó alguna palabra clave clara, utilizala (Ej: 'calificación', 'USD', 'proyecto completo', 'materias', 'kilos', 'libros')",
                    "deadline_months": integer (1-24, preferentemente 3),
                    "goal_type": "accumulation|project|threshold|challenge",
                    "area_id": integer (1-4),
                    "estimated_difficulty": integer (500-3000 XP)
                }
            }
S;

        $system = $this->buildSystemPrompt($instruction, $user);
        return $this->makeRequest($system, "Genera el Hito de Nivel Medio basándote en mis respuestas.", true);
    }

    private function generateLowLevelStrategy(string $chatHistory, User $user): array
    {
        $instruction = <<<S
        MODO: ENTRENADOR DE CAMPO Y ESTRATEGA DE HÁBITOS.
        Tu misión es aterrizar la visión del usuario en acciones tácticas inmediatas (Tareas y Hábitos).

        FILOSOFÍA DE EJECUCIÓN:
        - INTENCIONES DE IMPLEMENTACIÓN: No digas "hacer ejercicio". Di "Ir al gimnasio a las 08:00". El cerebro necesita coordenadas exactas.
        - IMPACTO (Tasks): Las tareas deben tener un "impact_value". Una tarea de alto impacto es algo que, al completarse, hace que el resto de las metas sean más fáciles o innecesarias.
        - ANTIFRAGILIDAD EN HÁBITOS: Si el hábito es difícil, sugiere un "plan de rescate" en la descripción (Ej: "Si no puedo correr 30 min, caminaré 5 min").

        HISTORIAL DE CONVERSACIÓN:
        $chatHistory

        TU TAREA:
        Diseña una combinación de 3 acciones (pueden ser 2 tareas y 1 hábito, o viceversa) que sean el "primer paso" más inteligente para el Hito Trimestral.

        REGLAS DE FORMATO (JSON):
        - Para 'habit': 'frequency_type' puede ser 'days' (fijos) o 'times' (cantidad). Si es 'days', llena 'scheduled_days' con ["Mon", "Tue", etc.]. Si es 'times', llena 'target_count_per_week'.
        - Para 'task': Define 'due_date' (YYYY-MM-DD) y 'due_time' (HH:mm).

        FORMATO JSON:
        {
            "message": "Tu mensaje motivador como entrenador, explicando por qué estas acciones específicas son las que moverán la aguja hoy...",
            "data": [

                {
                    "type": "task",
                    "name": "Nombre profesional y directo",
                    "plans": "Descripción detallada del plan de ejecución (Intención de implementación)",
                    "difficulty": integer (1-5),
                    "impact_value": integer (0),
                    "due_date": "YYYY-MM-DD",
                    "due_time": "HH:mm"
                },
                {
                    "type": "habit",
                    "name": "Nombre del hábito",
                    "plans": "Instrucciones de consistencia y plan de rescate",
                    "difficulty": integer (1-5),
                    "frequency_type": "days|times",
                    "scheduled_days": ["Mon", "Wed", "Fri"],
                    "target_count_per_week": null,
                    "scheduled_time": "HH:mm"
                }
            ]
        }
S;
        $system = $this->buildSystemPrompt($instruction, $user);
        return $this->makeRequest($system, "Genera el Plan de Acción.", true);
    }

    public function generateQuickWins(string $chatHistory, User $user): array
    {
        $instruction = <<<S
            MODO: COACH DE ACCIÓN INMEDIATA (QUICK WINS).
            Genera 3 acciones rápidas (tasks o habits) para romper la inercia HOY. Pueden ser 3 tareas, 3 hábitos o una combinación.

            HISTORIAL:
            $chatHistory

            DEVUELVE JSON EXACTO (KEYS EN INGLÉS):
            {
                "message": "Explica por qué decidiste crear estas 3 acciones rápidas y cómo se relacionan con lo que el usuario te dijo (EN EL IDIOMA DEL USUARIO)...",
                "data": [
                    { 
                        "type": "task",
                        "name": "Nombre específico aplicando SMART y HARD", 
                        "plans": "planes que nos ayudarían a completar la tarea",
                        "impact_value": (int) siempre debe ser 0,
                        "difficulty": (int 1-5) "1: Muy fácil, 5: Muy difícil", 
                        "due_date": "YYYY-MM-DD"
                    },
                    { 
                        "type": "habit",
                        "name": "Nombre específico aplicando SMART y HARD", 
                        "plans": "planes que nos ayudarían a completar el hábito",
                        "difficulty": (int 1-5) "1: Muy fácil, 5: Muy difícil", 
                        "frequency_type": enum("days", "times") si es days, llena la llave scheduled_days. Si es times, llena target_count_per_week,
                        "scheduled_days": (array of days of the week ["Mon", "Tus", "Wed"...]),
                        "target_count_per_week": (int 11),
                        "scheduled_time": "HH:mm (Ej: '08:00'). DEBE ser un formato de hora de 24h o null. NUNCA escribas texto aquí.",
                    },
                ]
            }
        S;
        $system = $this->buildSystemPrompt($instruction, $user);
        return $this->makeRequest($system, "Genera mis 3 Quick Wins ahora.", true);
    }

    public function analyzePastWeek(array $stats, int $gritScore, User $user): array
    {
        // Encode the weekly statistics to inject into the LLM context.
        $contextJson = json_encode($stats);

        $instruction = <<<S
            MODO: COACH ANALÍTICO Y EMPÁTICO.
            
            ESTÁS ANALIZANDO EL DESEMPEÑO DE LA SEMANA PASADA (Lunes a Domingo).
            
            === TUS DATOS DE ENTRADA (EL EXPEDIENTE) ===
            $contextJson

            === GUÍA DE INTERPRETACIÓN ===
            1. HISTORIAL: Mira "history". Si existe "rafael_advice" anterior, verifica si el usuario le hizo caso o repitió errores. Menciónalo ("La semana pasada te dije que...").
            2. BATALLAS (Tasks): 
                - [WIN] = Tarea completada. Fíjate si completó las de alta Dificultad (Esfuerzo).
                - [FAIL] = No completada. Fíjate si eran de alto Impacto (Resultado).
                - Diferencia bien: Difficulty es cuánto sudó, Impact es cuánto avanzó.
            3. HÁBITOS: Mira si fue constante.
            4. EMOCIONES: Mira "emotional_pulse". Si la energía fue baja, úsalo para justificar fallos, no para regañar.

            TU MISIÓN:
            Genera un feedback de 3 o 4 oraciones.
            - NO listes todo lo que hizo.
            - ELIGE el patrón más obvio (Ej: "Mucho trabajo duro (Difficulty) pero poco avance real (Impact)" o "Tuviste baja energía y eso afectó tus hábitos").
            - Sé específico: Nombra al menos una meta o tarea por su nombre.

            FORMATO JSON:
            {
                "message": "...",
                "data": {
                    "rafael_global_comment": "Tu análisis aquí."
                }
            }
S;
        $system = $this->buildSystemPrompt($instruction, $user);
        return $this->makeRequest($system, "Dame el análisis de mi expediente semanal.", true);
    }

    /**
     * Phase 2: Weekly Planning Blessing
     * Generates tactical advice based on the user's selected targets for the upcoming week.
     */
    public function givePlanningBlessing(WeeklyReview $review, User $user): array
    {
        $targets = $review->targets->map(function ($t) {
            $goalName = $t->goal->name;
            $xp = $t->xp_target;
            $diff = $t->difficulty_score;
            return "- Meta: '$goalName' (Apuesta: $xp XP, Dificultad percibida: $diff/5).";
        })->implode("\n");

        $instruction = <<<TEXT
            MODO: ESTRATEGA Y MENTOR DE EJECUCIÓN.
            
            CONTEXTO:
            El usuario ha definido su plan para la próxima semana.
            SU PLAN ES:
            $targets

            TU TAREA:
            1. Analiza la combinación de metas. ¿Hay conflictos? (Ej: mucho físico y mucho intelectual a la vez puede agotar).
            2. NO des solo ánimos vacíos.
            3. DAME 2 O 3 CONSEJOS TÁCTICOS O "GUÍAS DE SOBREVIVENCIA" específicas para lograr este plan concreto.
            4. Cierra con una frase estoica corta.

            FORMATO JSON:
            {
                "message": "...",
                "data": {
                    "blessing_message": "Tus consejos tácticos y cierre aquí."
                }
            }
TEXT;
        $system = $this->buildSystemPrompt($instruction, $user);
        return $this->makeRequest($system, "Dame mis guías tácticas para la semana.", true);
    }
}