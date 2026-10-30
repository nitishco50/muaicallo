package com.music.bitchord.allo

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.music.bitchord.ui.theme.BitChordTheme
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put

private const val STEP_COUNT = 5

/**
 * Mandatory first-run setup.
 *
 * Account creation and taste selection are one flow because an account with no
 * profile is a cold start for the recommender. The choices here become server
 * signals immediately, so the first Home feed has something to work with.
 */
class AlloSetupActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        AlloSession.init(this)
        AlloEvents.init(this)
        AlloRemoteConfig.load(this)
        setContent {
            BitChordTheme(darkTheme = androidx.compose.foundation.isSystemInDarkTheme()) {
                AlloSetupScreen(onDone = { finish() })
            }
        }
    }
}

private data class SetupAuth(val token: String, val email: String, val name: String)

private fun parseSetupAuth(data: JsonObject): SetupAuth? {
    val token = data["token"]?.jsonPrimitive?.contentOrNull ?: return null
    val user = data["user"]?.takeIf { it is JsonObject }?.jsonObject
    return SetupAuth(
        token = token,
        email = user?.get("email")?.jsonPrimitive?.contentOrNull.orEmpty(),
        name = user?.get("name")?.jsonPrimitive?.contentOrNull.orEmpty(),
    )
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun AlloSetupScreen(onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val alreadySignedIn by AlloSession.signedInState.collectAsStateWithLifecycle()

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

    val accountValid = name.isNotBlank() &&
        email.contains("@") &&
        password.length >= 8 &&
        (age.toIntOrNull() ?: 0) in 13..100

    fun goNext() {
        error = null
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
        busy = true
        error = null
        scope.launch {
            val preferences = buildJsonObject {
                put("age", age.toIntOrNull() ?: 0)
                put("gender", gender)
                put("listening_time", listeningTime)
                put("notifications", notifications)
                put("regions", buildJsonArray { regions.forEach { add(it) } })
                put("artists", buildJsonArray { artists.forEach { add(it) } })
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
                        AlloSession.save(auth.token, auth.email, auth.name)
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

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Set up allomusic") },
                navigationIcon = {
                    if (step > 0) {
                        IconButton(onClick = { if (!busy) step-- }) {
                            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                        }
                    }
                },
            )
        },
        bottomBar = {
            Column(Modifier.fillMaxWidth().padding(20.dp)) {
                error?.let {
                    Text(
                        it,
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.padding(bottom = 10.dp),
                    )
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (step > 0) {
                        OutlinedButton(
                            onClick = { if (!busy) step-- },
                            enabled = !busy,
                        ) { Text("Back") }
                        Spacer(Modifier.width(12.dp))
                    }
                    Button(
                        modifier = Modifier.weight(1f),
                        enabled = !busy && when (step) {
                            0 -> accountValid
                            1 -> regions.isNotEmpty()
                            else -> true
                        },
                        onClick = {
                            if (step == STEP_COUNT - 1) createAccount() else goNext()
                        },
                    ) {
                        Text(
                            when {
                                busy -> "Creating account…"
                                step == STEP_COUNT - 1 -> "Create account"
                                else -> "Continue"
                            },
                        )
                    }
                }
            }
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 20.dp),
        ) {
            LinearProgressIndicator(
                progress = { (step + 1f) / STEP_COUNT },
                modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
            )
            Column(
                modifier = Modifier
                    .weight(1f)
                    .verticalScroll(rememberScrollState())
                    .padding(top = 22.dp, bottom = 12.dp),
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
    }
}

@Composable
private fun StepHeading(title: String, subtitle: String) {
    Text(title, style = MaterialTheme.typography.headlineSmall)
    Text(
        subtitle,
        style = MaterialTheme.typography.bodyMedium,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
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
    OutlinedTextField(
        value = name,
        onValueChange = onName,
        label = { Text("Name") },
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )
    OutlinedTextField(
        value = email,
        onValueChange = onEmail,
        label = { Text("Email") },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
        modifier = Modifier.fillMaxWidth(),
    )
    OutlinedTextField(
        value = password,
        onValueChange = onPassword,
        label = { Text("Password (8+ characters)") },
        singleLine = true,
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
        modifier = Modifier.fillMaxWidth(),
    )
    OutlinedTextField(
        value = age,
        onValueChange = { age = it.filter(Char::isDigit).take(3) },
        label = { Text("Age") },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
        modifier = Modifier.fillMaxWidth(),
    )
    TextButton(onClick = onSignIn) {
        Text("I already have an account — sign in")
    }
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
            FilterChip(
                selected = isSelected(id),
                onClick = { onToggle(id) },
                label = { Text(label) },
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

@Composable
private fun SongsStep(regions: Set<String>, songs: Set<AlloSong>, onToggle: (AlloSong) -> Unit) {
    StepHeading(
        "Pick a few favourites",
        "Optional. Each song pulls in its artist, so your artist list starts from music you actually know.",
    )
    AlloCatalog.songsFor(regions).forEach { song ->
        FilterChip(
            selected = song in songs,
            onClick = { onToggle(song) },
            label = { Text("${song.title} · ${song.artist}") },
        )
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
        Text("From your songs", style = MaterialTheme.typography.titleSmall)
        FlowRow(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            songs.map { it.artist }.distinct().forEach { artist ->
                FilterChip(
                    selected = artist in artists,
                    onClick = { onToggle(artist) },
                    label = { Text(artist) },
                )
            }
        }
        Spacer(Modifier.height(6.dp))
    }
    Text("Popular in your regions", style = MaterialTheme.typography.titleSmall)
    val suggested = AlloCatalog.suggestedArtists(regions, emptySet())
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        suggested.forEach { artist ->
            FilterChip(
                selected = artist in artists,
                onClick = { onToggle(artist) },
                label = { Text(artist) },
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
    Text("Gender", style = MaterialTheme.typography.titleSmall)
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
    Text("When do you usually listen?", style = MaterialTheme.typography.titleSmall)
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
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text("Notices", style = MaterialTheme.typography.titleSmall)
            Text(
                "New releases and account messages from allomusic.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        Spacer(Modifier.width(12.dp))
        Switch(checked = notifications, onCheckedChange = onNotifications)
    }
    Spacer(Modifier.size(8.dp))
    Text(
        "By creating an account you agree that your listening signals are stored on the allomusic server so the app can personalise your feed.",
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
}
