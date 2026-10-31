package com.music.bitchord.allo

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.music.bitchord.R
import com.music.bitchord.ui.player.MESH_CYCLE_MILLIS
import com.music.bitchord.ui.player.MeshGradientBackground
import com.music.bitchord.ui.player.WelcomeMeshPalettes
import com.music.bitchord.ui.player.cyclingMeshPalette
import com.music.bitchord.ui.theme.BitChordTheme
import com.music.bitchord.ui.theme.SystemBarIcons
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put

private const val STEP_COUNT = 5
private val ErrorColor = Color(0xFFFFB4AB)

/**
 * Mandatory first-run setup.
 *
 * Account creation and taste selection are one flow because an account with no
 * profile is a cold start for the recommender. The choices here become server
 * signals immediately, so the first Home feed has something to work with.
 *
 * Drawn on the welcome mesh, always dark: the colour is the surface, so the
 * type, fields and buttons have to be white whatever the system theme is.
 */
class AlloSetupActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        AlloSession.init(this)
        AlloEvents.init(this)
        AlloRemoteConfig.load(this)
        setContent {
            BitChordTheme(darkTheme = true) {
                SystemBarIcons(dark = false)
                AlloSetupScreen(onDone = { finish() })
            }
        }
    }
}

private data class SetupAuth(
    val token: String,
    val email: String,
    val name: String,
    val userId: Long?,
    val uuid: String?,
)

private fun parseSetupAuth(data: JsonObject): SetupAuth? {
    val token = data["token"]?.jsonPrimitive?.contentOrNull ?: return null
    val user = data["user"]?.takeIf { it is JsonObject }?.jsonObject
    return SetupAuth(
        token = token,
        email = user?.get("email")?.jsonPrimitive?.contentOrNull.orEmpty(),
        name = user?.get("name")?.jsonPrimitive?.contentOrNull.orEmpty(),
        userId = user?.get("id")?.jsonPrimitive?.contentOrNull?.toLongOrNull(),
        uuid = user?.get("uuid")?.jsonPrimitive?.contentOrNull,
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AlloSetupScreen(onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focusManager = LocalFocusManager.current
    val alreadySignedIn by AlloSession.signedInState.collectAsStateWithLifecycle()
    val scrollState = rememberScrollState()

    var step by remember { mutableStateOf(0) }
    var name by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var age by remember { mutableStateOf("") }
    var regions by remember { mutableStateOf(emptySet<String>()) }
    var songs by remember { mutableStateOf(emptySet<AlloSong>()) }
    var artists by remember { mutableStateOf(emptySet<String>()) }
    var gender by remember { mutableStateOf("") }
    var listeningTime by remember { mutableStateOf("") }
    var notifications by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(alreadySignedIn) {
        if (alreadySignedIn) onDone()
    }

    LaunchedEffect(step) { scrollState.scrollTo(0) }

    val accountValid = name.isNotBlank() &&
        email.contains("@") &&
        password.length >= 8 &&
        (age.toIntOrNull() ?: 0) in 13..100

    fun goBack() {
        error = null
        focusManager.clearFocus()
        if (step > 0) step--
    }

    fun goNext() {
        error = null
        focusManager.clearFocus()
        when (step) {
            1 -> {
                songs = songs.filter { it.region in regions }.toSet()
                artists = artists.filter { it in AlloCatalog.suggestedArtists(regions, songs) }.toSet()
            }
            2 -> {
                artists = artists + songs.map { it.artist }
            }
        }
        if (step < STEP_COUNT - 1) step++
    }

    fun createAccount() {
        if (busy) return
        focusManager.clearFocus()
        busy = true
        error = null
        scope.launch {
            val preferences = buildJsonObject {
                put("age", age.toIntOrNull() ?: 0)
                put("gender", gender)
                put("listening_time", listeningTime)
                put("notifications", notifications)
                put("regions", buildJsonArray { regions.forEach { add(JsonPrimitive(it)) } })
                put("artists", buildJsonArray { artists.forEach { add(JsonPrimitive(it)) } })
                put("songs", buildJsonArray {
                    songs.forEach { song ->
                        add(buildJsonObject {
                            put("title", song.title)
                            put("artist", song.artist)
                            put("region", song.region)
                        })
                    }
                })
            }
            when (val result = AlloApi.register(
                email = email.trim(),
                password = password,
                name = name.trim(),
                device = AlloSession.device(context),
                preferences = preferences,
            )) {
                is AlloApi.Result.Ok -> {
                    val auth = parseSetupAuth(result.data)
                    if (auth == null) {
                        error = "The server response was incomplete."
                    } else {
                        AlloSession.save(auth.token, auth.email, auth.name, auth.userId, auth.uuid)
                        AlloSync.pushSettings(context)
                        AlloEvents.flushSoon()
                        onDone()
                    }
                }
                is AlloApi.Result.Err -> error = result.message
            }
            busy = false
        }
    }

    BackHandler(enabled = step > 0 && !busy) { goBack() }

    val palette = cyclingMeshPalette(WelcomeMeshPalettes)

    Box(
        Modifier
            .fillMaxSize()
            .background(Color.Black),
    ) {
        MeshGradientBackground(
            palette = palette,
            trackKey = step,
            continuous = true,
            driftMillis = 6_000,
            colorFadeMillis = MESH_CYCLE_MILLIS,
        )
        Box(
            Modifier
                .fillMaxSize()
                .background(
                    Brush.verticalGradient(
                        0f to Color.Black.copy(alpha = 0.05f),
                        0.55f to Color.Transparent,
                        1f to Color.Black.copy(alpha = 0.45f),
                    ),
                ),
        )

        Column(
            Modifier
                .fillMaxSize()
                .systemBarsPadding()
                .imePadding()
                .padding(horizontal = 28.dp),
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(top = 18.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Image(
                    painter = painterResource(R.drawable.ic_logo),
                    contentDescription = null,
                    modifier = Modifier.size(width = 46.dp, height = 30.dp),
                )
                Spacer(Modifier.weight(1f))
                Text(
                    text = "${step + 1} / $STEP_COUNT",
                    style = MaterialTheme.typography.labelLarge,
                    color = Color.White.copy(alpha = 0.6f),
                )
            }
            Spacer(Modifier.height(16.dp))
            SetupProgress(step)

            Box(Modifier.weight(1f), contentAlignment = Alignment.TopCenter) {
                Column(
                    modifier = Modifier
                        .widthIn(max = 520.dp)
                        .fillMaxSize()
                        .verticalScroll(scrollState)
                        .padding(top = 26.dp, bottom = 16.dp),
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                ) {
                    when (step) {
                        0 -> AccountStep(
                            name, { name = it },
                            email, { email = it },
                            password, { password = it },
                            age, { age = it },
                            onSignIn = {
                                context.startActivity(
                                    Intent(context, AlloAccountActivity::class.java)
                                        .putExtra(AlloAccountActivity.EXTRA_GATE, true),
                                )
                            },
                        )
                        1 -> RegionsStep(regions) { id ->
                            regions = if (id in regions) regions - id else regions + id
                        }
                        2 -> SongsStep(regions, songs) { song ->
                            songs = if (song in songs) songs - song else songs + song
                        }
                        3 -> ArtistsStep(
                            regions = regions,
                            songs = songs,
                            artists = artists,
                        ) { artist ->
                            artists = if (artist in artists) artists - artist else artists + artist
                        }
                        else -> DetailsStep(
                            gender, { gender = it },
                            listeningTime, { listeningTime = it },
                            notifications, { notifications = it },
                        )
                    }
                }
            }

            error?.let {
                Text(
                    text = it,
                    color = ErrorColor,
                    style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier.padding(bottom = 10.dp),
                )
            }

            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(bottom = 18.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (step > 0) {
                    SetupPillButton(
                        label = "Back",
                        onClick = { goBack() },
                        primary = false,
                        enabled = !busy,
                        modifier = Modifier.width(104.dp),
                    )
                }
                SetupPillButton(
                    label = when {
                        busy -> "Creating account…"
                        step == STEP_COUNT - 1 -> "Create account"
                        else -> "Continue"
                    },
                    onClick = {
                        if (step == STEP_COUNT - 1) createAccount() else goNext()
                    },
                    primary = true,
                    enabled = !busy && when (step) {
                        0 -> accountValid
                        1 -> regions.isNotEmpty()
                        else -> true
                    },
                    modifier = Modifier.weight(1f),
                )
            }
        }
    }
}

@Composable
private fun SetupProgress(step: Int) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        repeat(STEP_COUNT) { index ->
            Box(
                Modifier
                    .weight(1f)
                    .height(3.dp)
                    .clip(CircleShape)
                    .background(Color.White.copy(alpha = if (index <= step) 1f else 0.22f)),
            )
        }
    }
}

@Composable
private fun StepHeading(title: String, subtitle: String) {
    Text(
        text = title,
        style = MaterialTheme.typography.displayLarge.copy(
            fontSize = 38.sp,
            lineHeight = 42.sp,
            letterSpacing = (-1.1).sp,
        ),
        color = Color.White,
    )
    Text(
        text = subtitle,
        style = MaterialTheme.typography.bodyLarge.copy(fontSize = 17.sp, lineHeight = 23.sp),
        color = Color.White.copy(alpha = 0.72f),
    )
}

@Composable
private fun AccountStep(
    name: String, onName: (String) -> Unit,
    email: String, onEmail: (String) -> Unit,
    password: String, onPassword: (String) -> Unit,
    age: String, onAge: (String) -> Unit,
    onSignIn: () -> Unit,
) {
    StepHeading(
        "Create your account",
        "Your history, likes and taste profile stay on your own allomusic account.",
    )
    Spacer(Modifier.height(4.dp))
    SetupField(
        value = name,
        onValueChange = onName,
        placeholder = "Name",
        keyboardOptions = KeyboardOptions(
            capitalization = KeyboardCapitalization.Words,
            autoCorrectEnabled = false,
            imeAction = ImeAction.Next,
        ),
    )
    SetupField(
        value = email,
        onValueChange = onEmail,
        placeholder = "Email",
        keyboardOptions = KeyboardOptions(
            keyboardType = KeyboardType.Email,
            autoCorrectEnabled = false,
            imeAction = ImeAction.Next,
        ),
    )
    SetupField(
        value = password,
        onValueChange = onPassword,
        placeholder = "Password (8+ characters)",
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(
            keyboardType = KeyboardType.Password,
            autoCorrectEnabled = false,
            imeAction = ImeAction.Next,
        ),
    )
    SetupField(
        value = age,
        onValueChange = { onAge(it.filter(Char::isDigit).take(3)) },
        placeholder = "Age",
        keyboardOptions = KeyboardOptions(
            keyboardType = KeyboardType.Number,
            imeAction = ImeAction.Done,
        ),
    )
    Text(
        text = "I already have an account — sign in",
        style = MaterialTheme.typography.bodyMedium,
        color = Color.White.copy(alpha = 0.78f),
        modifier = Modifier
            .padding(start = 4.dp)
            .clickable(role = Role.Button, onClick = onSignIn),
    )
}

@Composable
private fun SetupField(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    modifier: Modifier = Modifier,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    visualTransformation: VisualTransformation = VisualTransformation.None,
) {
    val style = MaterialTheme.typography.bodyLarge.copy(
        fontSize = 17.sp,
        fontWeight = FontWeight.W500,
        color = Color.White,
    )
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(58.dp)
            .clip(CircleShape)
            .background(Color.White.copy(alpha = 0.14f))
            .padding(horizontal = 22.dp),
        contentAlignment = Alignment.CenterStart,
    ) {
        if (value.isEmpty()) {
            Text(
                text = placeholder,
                style = style,
                color = Color.White.copy(alpha = 0.38f),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        BasicTextField(
            value = value,
            onValueChange = onValueChange,
            singleLine = true,
            textStyle = style,
            cursorBrush = SolidColor(Color.White),
            keyboardOptions = keyboardOptions,
            keyboardActions = KeyboardActions.Default,
            visualTransformation = visualTransformation,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun SetupChip(label: String, selected: Boolean, onClick: () -> Unit) {
    val background by animateColorAsState(
        targetValue = if (selected) Color.White else Color.White.copy(alpha = 0.14f),
        animationSpec = tween(160),
        label = "chipBackground",
    )
    val foreground by animateColorAsState(
        targetValue = if (selected) Color.Black else Color.White,
        animationSpec = tween(160),
        label = "chipForeground",
    )
    Box(
        modifier = Modifier
            .clip(CircleShape)
            .background(background)
            .clickable(role = Role.Checkbox, onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 10.dp),
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.labelLarge.copy(fontWeight = FontWeight.W600),
            color = foreground,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun SetupSongChip(song: AlloSong, selected: Boolean, onClick: () -> Unit) {
    val background by animateColorAsState(
        targetValue = if (selected) Color.White else Color.White.copy(alpha = 0.14f),
        animationSpec = tween(160),
        label = "songChipBackground",
    )
    val titleColor by animateColorAsState(
        targetValue = if (selected) Color.Black else Color.White,
        animationSpec = tween(160),
        label = "songChipTitle",
    )
    val artistColor by animateColorAsState(
        targetValue = if (selected) Color.Black.copy(alpha = 0.65f) else Color.White.copy(alpha = 0.62f),
        animationSpec = tween(160),
        label = "songChipArtist",
    )
    Column(
        modifier = Modifier
            .clip(CircleShape)
            .background(background)
            .clickable(role = Role.Checkbox, onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 9.dp),
    ) {
        Text(
            text = song.title,
            style = MaterialTheme.typography.labelLarge.copy(fontWeight = FontWeight.W600),
            color = titleColor,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Text(
            text = song.artist,
            style = MaterialTheme.typography.labelSmall,
            color = artistColor,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleSmall.copy(fontWeight = FontWeight.W600),
        color = Color.White.copy(alpha = 0.7f),
        modifier = Modifier.padding(top = 6.dp),
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ChipGroup(
    values: List<Pair<String, String>>,
    isSelected: (String) -> Boolean,
    onToggle: (String) -> Unit,
) {
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        values.forEach { (id, label) ->
            SetupChip(
                label = label,
                selected = isSelected(id),
                onClick = { onToggle(id) },
            )
        }
    }
}

@Composable
private fun RegionsStep(regions: Set<String>, onToggle: (String) -> Unit) {
    StepHeading(
        "Which music do you love?",
        "Select every region or language you listen to. This shapes the artists we suggest next.",
    )
    ChipGroup(
        values = AlloCatalog.regions.map { it.id to it.label },
        isSelected = { it in regions },
        onToggle = onToggle,
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SongsStep(regions: Set<String>, songs: Set<AlloSong>, onToggle: (AlloSong) -> Unit) {
    StepHeading(
        "Pick a few favourites",
        "Optional. Each song pulls in its artist, so your artist list starts from music you actually know.",
    )
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        AlloCatalog.songsFor(regions).forEach { song ->
            SetupSongChip(
                song = song,
                selected = song in songs,
                onClick = { onToggle(song) },
            )
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ArtistsStep(
    regions: Set<String>,
    songs: Set<AlloSong>,
    artists: Set<String>,
    onToggle: (String) -> Unit,
) {
    StepHeading(
        "Artists you follow",
        "These are suggested from your songs and regions. Add or remove anyone.",
    )
    if (songs.isNotEmpty()) {
        SectionLabel("From your songs")
        FlowRow(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            songs.map { it.artist }.distinct().forEach { artist ->
                SetupChip(
                    label = artist,
                    selected = artist in artists,
                    onClick = { onToggle(artist) },
                )
            }
        }
    }
    SectionLabel("Popular in your regions")
    val suggested = AlloCatalog.suggestedArtists(regions, emptySet())
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        suggested.forEach { artist ->
            SetupChip(
                label = artist,
                selected = artist in artists,
                onClick = { onToggle(artist) },
            )
        }
    }
}

@Composable
private fun DetailsStep(
    gender: String, onGender: (String) -> Unit,
    listeningTime: String, onListeningTime: (String) -> Unit,
    notifications: Boolean, onNotifications: (Boolean) -> Unit,
) {
    StepHeading(
        "A little more",
        "Optional, but it helps the recommender understand when and how you listen.",
    )
    SectionLabel("Gender")
    ChipGroup(
        values = listOf(
            "male" to "Male",
            "female" to "Female",
            "other" to "Other",
            "private" to "Rather not say",
        ),
        isSelected = { it == gender },
        onToggle = { onGender(if (gender == it) "" else it) },
    )
    SectionLabel("When do you usually listen?")
    ChipGroup(
        values = listOf(
            "morning" to "Morning",
            "afternoon" to "Afternoon",
            "evening" to "Evening",
            "night" to "Late night",
        ),
        isSelected = { it == listeningTime },
        onToggle = { onListeningTime(if (listeningTime == it) "" else it) },
    )
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(top = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(
                text = "Notices",
                style = MaterialTheme.typography.titleSmall.copy(fontWeight = FontWeight.W600),
                color = Color.White,
            )
            Text(
                text = "New releases and account messages from allomusic.",
                style = MaterialTheme.typography.bodySmall,
                color = Color.White.copy(alpha = 0.62f),
            )
        }
        Spacer(Modifier.width(12.dp))
        Switch(
            checked = notifications,
            onCheckedChange = onNotifications,
            colors = SwitchDefaults.colors(
                checkedThumbColor = Color.Black,
                checkedTrackColor = Color.White,
                uncheckedThumbColor = Color.White,
                uncheckedTrackColor = Color.White.copy(alpha = 0.22f),
                uncheckedBorderColor = Color.Transparent,
            ),
        )
    }
    Text(
        text = "By creating an account you agree that your listening signals are stored on the allomusic server so the app can personalise your feed.",
        style = MaterialTheme.typography.bodySmall.copy(lineHeight = 17.sp),
        color = Color.White.copy(alpha = 0.5f),
        modifier = Modifier.padding(top = 4.dp),
    )
}

/**
 * A full-width capsule. Primary is white on the mesh, the way Apple Music's
 * own welcome sheet does it; secondary is the same shape in frosted white, so
 * the pair reads as a choice rather than a button and an afterthought.
 */
@Composable
private fun SetupPillButton(
    label: String,
    onClick: () -> Unit,
    primary: Boolean,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    leading: (@Composable () -> Unit)? = null,
) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(if (pressed) 0.97f else 1f, spring(stiffness = 900f), label = "pillScale")
    val alpha by animateFloatAsState(if (enabled) 1f else 0.4f, tween(180), label = "pillAlpha")
    Row(
        modifier = modifier
            .height(56.dp)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
                this.alpha = alpha
            }
            .clip(CircleShape)
            .background(if (primary) Color.White else Color.White.copy(alpha = 0.16f))
            .clickable(
                interactionSource = interaction,
                indication = null,
                enabled = enabled,
                role = Role.Button,
                onClick = onClick,
            ),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (leading != null) {
            leading()
            Spacer(Modifier.width(10.dp))
        }
        Text(
            text = label,
            style = MaterialTheme.typography.titleMedium,
            fontSize = 17.sp,
            fontWeight = FontWeight.W700,
            color = if (primary) Color.Black else Color.White,
            textAlign = TextAlign.Center,
            maxLines = 1,
        )
    }
}
