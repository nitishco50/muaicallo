package com.music.bitchord.allo

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Bookmark
import androidx.compose.material.icons.rounded.BookmarkBorder
import androidx.compose.material.icons.rounded.ChatBubbleOutline
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.FavoriteBorder
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Reply
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.ThumbDown
import androidx.compose.material.icons.rounded.ThumbDownOffAlt
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.AsyncImage
import com.music.bitchord.data.model.Song
import com.music.bitchord.ui.theme.BitChordTheme
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter

/**
 * The allomusic profile: thirty days of listening, likes, bookmarks, comments,
 * trending songs across the community and a song search. It is deliberately a
 * separate activity from the player so the social pages can grow without
 * touching the playback stack.
 */
class AlloHubActivity : ComponentActivity() {

    companion object {
        private const val EXTRA_SONG_KEY = "song_key"
        private const val EXTRA_SONG_TITLE = "song_title"
        private const val EXTRA_SONG_ARTIST = "song_artist"
        private const val EXTRA_SONG_ART = "song_art"

        /** Opens the hub straight on a song's comment sheet. */
        fun startForSong(context: Context, song: Song) {
            val ref = AlloSocial.songRef(song)
            val intent = Intent(context, AlloHubActivity::class.java).apply {
                putExtra(EXTRA_SONG_KEY, ref.songKey)
                putExtra(EXTRA_SONG_TITLE, ref.title)
                putExtra(EXTRA_SONG_ARTIST, ref.artist)
                putExtra(EXTRA_SONG_ART, ref.artUrl)
            }
            if (context !is Activity) intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(intent)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        AlloSession.init(this)
        val songKey = intent.getStringExtra(EXTRA_SONG_KEY)
        val initialSong = songKey?.takeIf { it.isNotBlank() }?.let {
            AlloSocial.songRef(
                songKey = it,
                title = intent.getStringExtra(EXTRA_SONG_TITLE).orEmpty(),
                artist = intent.getStringExtra(EXTRA_SONG_ARTIST).orEmpty(),
                artUrl = intent.getStringExtra(EXTRA_SONG_ART).orEmpty(),
            )
        }
        setContent {
            BitChordTheme(darkTheme = androidx.compose.foundation.isSystemInDarkTheme()) {
                AlloHubScreen(onClose = { finish() }, initialSong = initialSong)
            }
        }
    }
}

private enum class HubTab(val label: String) {
    OVERVIEW("Overview"),
    HISTORY("History"),
    LIKED("Liked"),
    SAVED("Saved"),
    COMMENTS("My comments"),
    TRENDING("Trending"),
    SEARCH("Search"),
}

private data class HubState(
    val history: JsonObject? = null,
    val liked: List<SocialSong> = emptyList(),
    val saved: List<SocialSong> = emptyList(),
    val myComments: List<SocialComment> = emptyList(),
    val trending: List<SocialSong> = emptyList(),
    val searchResults: List<SocialSong> = emptyList(),
    val searchQuery: String = "",
    val loading: Boolean = false,
    val error: String? = null,
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AlloHubScreen(onClose: () -> Unit, initialSong: SocialSong? = null) {
    val token = AlloSession.token
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf(HubTab.OVERVIEW) }
    var state by remember { mutableStateOf(HubState()) }
    var refreshKey by remember { mutableIntStateOf(0) }
    var commentSong by remember { mutableStateOf(initialSong) }
    var confirmClear by remember { mutableStateOf(false) }

    fun load(activeTab: HubTab = tab, query: String = state.searchQuery) {
        if (token == null) {
            state = state.copy(error = "Sign in to see your allomusic profile.")
            return
        }
        scope.launch {
            state = state.copy(loading = true, error = null)
            state = when (activeTab) {
                HubTab.OVERVIEW, HubTab.HISTORY -> when (val r = AlloApi.history(token, 30, 300)) {
                    is AlloApi.Result.Ok -> state.copy(history = r.data, loading = false)
                    is AlloApi.Result.Err -> state.copy(error = r.message, loading = false)
                }
                HubTab.LIKED -> when (val r = AlloApi.reactions(token, "like")) {
                    is AlloApi.Result.Ok -> state.copy(liked = AlloSocial.parseSongs(r.data, "reactions"), loading = false)
                    is AlloApi.Result.Err -> state.copy(error = r.message, loading = false)
                }
                HubTab.SAVED -> when (val r = AlloApi.bookmarks(token)) {
                    is AlloApi.Result.Ok -> state.copy(saved = AlloSocial.parseSongs(r.data, "bookmarks"), loading = false)
                    is AlloApi.Result.Err -> state.copy(error = r.message, loading = false)
                }
                HubTab.COMMENTS -> when (val r = AlloApi.myComments(token)) {
                    is AlloApi.Result.Ok -> state.copy(myComments = AlloSocial.parseComments(r.data), loading = false)
                    is AlloApi.Result.Err -> state.copy(error = r.message, loading = false)
                }
                HubTab.TRENDING -> when (val r = AlloApi.trending(token)) {
                    is AlloApi.Result.Ok -> state.copy(trending = AlloSocial.parseSongs(r.data, "songs"), loading = false)
                    is AlloApi.Result.Err -> state.copy(error = r.message, loading = false)
                }
                HubTab.SEARCH -> if (query.isBlank()) {
                    state.copy(searchResults = emptyList(), loading = false)
                } else {
                    when (val r = AlloApi.searchSongs(token, query)) {
                        is AlloApi.Result.Ok -> state.copy(
                            searchResults = AlloSocial.parseSongs(r.data, "songs"),
                            loading = false,
                        )
                        is AlloApi.Result.Err -> state.copy(error = r.message, loading = false)
                    }
                }
            }
        }
    }

    LaunchedEffect(tab, refreshKey) { load(tab) }

    fun updated(song: SocialSong) {
        state = state.copy(
            liked = if (song.myReaction == "like") {
                listOf(song) + state.liked.filter { it.songKey != song.songKey }
            } else {
                state.liked.filter { it.songKey != song.songKey }
            },
            saved = state.saved.map { if (it.songKey == song.songKey) song else it },
            trending = state.trending.map { if (it.songKey == song.songKey) song else it },
            searchResults = state.searchResults.map { if (it.songKey == song.songKey) song else it },
        )
    }

    fun react(song: SocialSong, action: String) {
        val t = token ?: return
        scope.launch {
            when (val r = AlloApi.reaction(t, song.songKey, action, song.title, song.artist, song.artUrl)) {
                is AlloApi.Result.Ok -> updated(AlloSocial.parseSong(r.data))
                is AlloApi.Result.Err -> state = state.copy(error = r.message)
            }
        }
    }

    fun bookmark(song: SocialSong) {
        val t = token ?: return
        scope.launch {
            when (val r = AlloApi.bookmark(t, song.songKey, "toggle", song.title, song.artist, song.artUrl)) {
                is AlloApi.Result.Ok -> updated(AlloSocial.parseSong(r.data))
                is AlloApi.Result.Err -> state = state.copy(error = r.message)
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Your allomusic") },
                navigationIcon = {
                    IconButton(onClick = onClose) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding),
        ) {
            LazyRow(
                modifier = Modifier.fillMaxWidth(),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(HubTab.values()) { entry ->
                    FilterChip(
                        selected = entry == tab,
                        onClick = {
                            tab = entry
                            state = state.copy(error = null)
                        },
                        label = { Text(entry.label) },
                    )
                }
            }

            if (state.loading) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
                return@Column
            }

            state.error?.let { message ->
                Text(
                    text = message,
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier.padding(16.dp),
                )
            }

            when (tab) {
                HubTab.OVERVIEW -> OverviewTab(
                    history = state.history,
                    onSong = { commentSong = it },
                    onReact = ::react,
                    onBookmark = ::bookmark,
                    onComment = { commentSong = it },
                )
                HubTab.HISTORY -> HistoryTab(
                    history = state.history,
                    onClear = { confirmClear = true },
                )
                HubTab.LIKED -> SongListTab(
                    songs = state.liked,
                    empty = "You have not liked a song yet.",
                    onSong = { commentSong = it },
                    onReact = ::react,
                    onBookmark = ::bookmark,
                    onComment = { commentSong = it },
                )
                HubTab.SAVED -> SongListTab(
                    songs = state.saved,
                    empty = "No bookmarks yet. Tap the bookmark on any song.",
                    onSong = { commentSong = it },
                    onReact = ::react,
                    onBookmark = ::bookmark,
                    onComment = { commentSong = it },
                )
                HubTab.COMMENTS -> CommentsTab(
                    comments = state.myComments,
                    onOpen = { comment ->
                        commentSong = AlloSocial.songRef(comment.songKey, "Song", "")
                    },
                    onDelete = { comment ->
                        token?.let { t ->
                            scope.launch {
                                if (AlloApi.deleteComment(t, comment.id) is AlloApi.Result.Ok) {
                                    state = state.copy(myComments = state.myComments.filter { it.id != comment.id })
                                }
                            }
                        }
                    },
                )
                HubTab.TRENDING -> SongListTab(
                    songs = state.trending,
                    empty = "Nothing trending in the community yet.",
                    onSong = { commentSong = it },
                    onReact = ::react,
                    onBookmark = ::bookmark,
                    onComment = { commentSong = it },
                )
                HubTab.SEARCH -> SearchTab(
                    query = state.searchQuery,
                    results = state.searchResults,
                    onQuery = { state = state.copy(searchQuery = it) },
                    onSearch = { load(HubTab.SEARCH, state.searchQuery) },
                    onSong = { commentSong = it },
                    onReact = ::react,
                    onBookmark = ::bookmark,
                    onComment = { commentSong = it },
                )
            }
        }
    }

    commentSong?.let { song ->
        val t = token ?: return@let
        ModalBottomSheet(onDismissRequest = { commentSong = null }) {
            CommentSheet(
                token = t,
                song = song,
                onSongChanged = ::updated,
                onClose = { commentSong = null },
            )
        }
    }

    if (confirmClear) {
        AlertDialog(
            onDismissRequest = { confirmClear = false },
            title = { Text("Clear 30-day history?") },
            text = { Text("This removes the last 30 days of plays from your allomusic history and recomputes your signals. Likes, bookmarks and comments stay.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmClear = false
                    val t = token ?: return@TextButton
                    scope.launch {
                        if (AlloApi.clearHistory(t, 30) is AlloApi.Result.Ok) {
                            refreshKey++
                        }
                    }
                }) { Text("Clear history") }
            },
            dismissButton = {
                TextButton(onClick = { confirmClear = false }) { Text("Cancel") }
            },
        )
    }
}

@Composable
private fun OverviewTab(
    history: JsonObject?,
    onSong: (SocialSong) -> Unit,
    onReact: (SocialSong, String) -> Unit,
    onBookmark: (SocialSong) -> Unit,
    onComment: (SocialSong) -> Unit,
) {
    if (history == null) {
        EmptyText("Your profile is loading.")
        return
    }
    val stats = history.obj("stats")
    val topSongs = AlloSocial.parseSongs(history, "top_songs")
    val topArtists = AlloSocial.objList(history, "top_artists")
    val daily = AlloSocial.objList(history, "daily")
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(18.dp)) {
                    Text(
                        AlloSession.name?.takeIf { it.isNotBlank() } ?: "Listener",
                        style = MaterialTheme.typography.headlineSmall,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        AlloSession.email.orEmpty(),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(16.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        StatBox("Plays", stats.int("plays").toString(), Modifier.weight(1f))
                        StatBox("Minutes", stats.int("minutes").toString(), Modifier.weight(1f))
                        StatBox("Songs", stats.int("unique_songs").toString(), Modifier.weight(1f))
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        StatBox("Artists", stats.int("unique_artists").toString(), Modifier.weight(1f))
                        StatBox("Liked", stats.int("likes_given").toString(), Modifier.weight(1f))
                        StatBox("Saved", stats.int("bookmarks").toString(), Modifier.weight(1f))
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        StatBox("Comments", stats.int("comments").toString(), Modifier.weight(1f))
                        StatBox("Followers", stats.int("followers").toString(), Modifier.weight(1f))
                        StatBox("Following", stats.int("following").toString(), Modifier.weight(1f))
                    }
                }
            }
        }
        item { Text("Last 30 days", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
        item { ActivityChart(daily) }
        if (topSongs.isNotEmpty()) {
            item { Text("Top songs", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
            items(topSongs, key = { "top-${it.songKey}" }) { song ->
                SocialSongRow(
                    song = song,
                    onOpen = { onSong(song) },
                    onReact = { action -> onReact(song, action) },
                    onBookmark = { onBookmark(song) },
                    onComment = { onComment(song) },
                )
            }
        }
        if (topArtists.isNotEmpty()) {
            item { Text("Top artists", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
            items(topArtists, key = { "artist-${it.str("artist")}" }) { artist ->
                Row(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(artist.str("artist"), style = MaterialTheme.typography.titleSmall)
                        Text(
                            "${artist.int("plays")} plays · ${artist.int("listen_ms") / 60000} min",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ActivityChart(daily: List<JsonObject>) {
    val max = daily.maxOfOrNull { it.int("plays") }?.coerceAtLeast(1) ?: 1
    Column(Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.fillMaxWidth().height(72.dp),
            verticalAlignment = Alignment.Bottom,
            horizontalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            daily.forEach { day ->
                val plays = day.int("plays")
                val fraction = plays.toFloat() / max
                Box(
                    modifier = Modifier
                        .weight(1f)
                        .fillMaxHeight()
                        .padding(top = 4.dp),
                    contentAlignment = Alignment.BottomCenter,
                ) {
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height((6f + fraction * 58f).dp)
                            .clip(RoundedCornerShape(3.dp))
                            .background(
                                if (plays > 0) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.surfaceVariant,
                            ),
                    )
                }
            }
        }
        Text(
            "Last 30 days · today on the right",
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(top = 4.dp),
        )
    }
}

@Composable
private fun StatBox(label: String, value: String, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier
            .clip(RoundedCornerShape(14.dp))
            .background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.55f))
            .padding(vertical = 10.dp, horizontal = 8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun HistoryTab(history: JsonObject?, onClear: () -> Unit) {
    if (history == null) {
        EmptyText("Your history is loading.")
        return
    }
    val plays = AlloSocial.objList(history, "plays")
    Column(Modifier.fillMaxSize()) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "${plays.size} plays in the last 30 days",
                style = MaterialTheme.typography.titleSmall,
                modifier = Modifier.weight(1f),
            )
            TextButton(onClick = onClear) { Text("Clear") }
        }
        if (plays.isEmpty()) {
            EmptyText("No plays yet. Listen to something and it will appear here.")
        } else {
            LazyColumn(
                modifier = Modifier.weight(1f),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 16.dp, vertical = 4.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(plays, key = { "${it.str("song_key")}-${it.str("created_at")}" }) { play ->
                    HistoryRow(play)
                }
            }
        }
    }
}

@Composable
private fun HistoryRow(play: JsonObject) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Cover(play.str("art_url"), 48.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                play.str("title").ifBlank { "Unknown song" },
                style = MaterialTheme.typography.titleSmall,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                play.str("artist").ifBlank { "Unknown artist" },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(prettyTime(play.str("created_at")), style = MaterialTheme.typography.labelSmall)
            Text(
                "${play.int("listen_ms") / 1000}s",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun SongListTab(
    songs: List<SocialSong>,
    empty: String,
    onSong: (SocialSong) -> Unit,
    onReact: (SocialSong, String) -> Unit,
    onBookmark: (SocialSong) -> Unit,
    onComment: (SocialSong) -> Unit,
) {
    if (songs.isEmpty()) {
        EmptyText(empty)
        return
    }
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        items(songs, key = { it.songKey }) { song ->
            SocialSongRow(
                song = song,
                onOpen = { onSong(song) },
                onReact = { action -> onReact(song, action) },
                onBookmark = { onBookmark(song) },
                onComment = { onComment(song) },
            )
        }
    }
}

@Composable
private fun CommentsTab(
    comments: List<SocialComment>,
    onOpen: (SocialComment) -> Unit,
    onDelete: (SocialComment) -> Unit,
) {
    if (comments.isEmpty()) {
        EmptyText("You have not commented yet.")
        return
    }
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        items(comments, key = { it.id }) { comment ->
            Card(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
                Column(Modifier.padding(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("You", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.width(8.dp))
                        Text(
                            prettyTime(comment.createdAt),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.weight(1f),
                        )
                        IconButton(onClick = { onDelete(comment) }, modifier = Modifier.size(32.dp)) {
                            Icon(Icons.Rounded.Delete, contentDescription = "Delete comment", modifier = Modifier.size(18.dp))
                        }
                    }
                    Text(comment.body, style = MaterialTheme.typography.bodyMedium)
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            "♥ ${comment.likes}",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        TextButton(onClick = { onOpen(comment) }) { Text("Open thread") }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SearchTab(
    query: String,
    results: List<SocialSong>,
    onQuery: (String) -> Unit,
    onSearch: () -> Unit,
    onSong: (SocialSong) -> Unit,
    onReact: (SocialSong, String) -> Unit,
    onBookmark: (SocialSong) -> Unit,
    onComment: (SocialSong) -> Unit,
) {
    Column(Modifier.fillMaxSize()) {
        OutlinedTextField(
            value = query,
            onValueChange = onQuery,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
            singleLine = true,
            placeholder = { Text("Search songs by title or artist") },
            trailingIcon = {
                IconButton(onClick = onSearch) {
                    Icon(Icons.Rounded.Search, contentDescription = "Search")
                }
            },
        )
        if (results.isEmpty()) {
            EmptyText("Search the allomusic community.")
        } else {
            LazyColumn(
                modifier = Modifier.weight(1f),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                items(results, key = { it.songKey }) { song ->
                    SocialSongRow(
                        song = song,
                        onOpen = { onSong(song) },
                        onReact = { action -> onReact(song, action) },
                        onBookmark = { onBookmark(song) },
                        onComment = { onComment(song) },
                    )
                }
            }
        }
    }
}

@Composable
private fun SocialSongRow(
    song: SocialSong,
    onOpen: () -> Unit,
    onReact: (String) -> Unit,
    onBookmark: () -> Unit,
    onComment: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Cover(song.artUrl, 52.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                song.title.ifBlank { "Unknown song" },
                style = MaterialTheme.typography.titleSmall,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                song.artist.ifBlank { "Unknown artist" },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                "${song.likes} likes · ${song.comments} comments",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        IconButton(onClick = { onReact(if (song.myReaction == "like") "clear" else "like") }) {
            Icon(
                if (song.myReaction == "like") Icons.Rounded.Favorite else Icons.Rounded.FavoriteBorder,
                contentDescription = "Like",
                tint = if (song.myReaction == "like") MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        IconButton(onClick = { onReact(if (song.myReaction == "dislike") "clear" else "dislike") }) {
            Icon(
                if (song.myReaction == "dislike") Icons.Rounded.ThumbDown else Icons.Rounded.ThumbDownOffAlt,
                contentDescription = "Dislike",
                tint = if (song.myReaction == "dislike") MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        IconButton(onClick = onBookmark) {
            Icon(
                if (song.bookmarked) Icons.Rounded.Bookmark else Icons.Rounded.BookmarkBorder,
                contentDescription = "Bookmark",
                tint = if (song.bookmarked) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        IconButton(onClick = onComment) {
            Icon(Icons.Rounded.ChatBubbleOutline, contentDescription = "Comments")
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CommentSheet(
    token: String,
    song: SocialSong,
    onSongChanged: (SocialSong) -> Unit,
    onClose: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var comments by remember { mutableStateOf<List<SocialComment>>(emptyList()) }
    var songState by remember { mutableStateOf(song) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var draft by remember { mutableStateOf("") }
    var replyTo by remember { mutableStateOf<SocialComment?>(null) }
    var sending by remember { mutableStateOf(false) }

    fun reload() {
        scope.launch {
            loading = true
            when (val r = AlloApi.comments(token, song.songKey)) {
                is AlloApi.Result.Ok -> {
                    comments = AlloSocial.parseComments(r.data)
                    songState = AlloSocial.parseSong(r.data.obj("song"))
                    onSongChanged(songState)
                    error = null
                }
                is AlloApi.Result.Err -> error = r.message
            }
            loading = false
        }
    }

    LaunchedEffect(song.songKey) { reload() }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .fillMaxHeight(0.9f)
            .padding(horizontal = 18.dp),
    ) {
        Text(
            song.title.ifBlank { "Song" },
            style = MaterialTheme.typography.titleLarge,
            fontWeight = FontWeight.Bold,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Text(
            "${songState.likes} likes · ${songState.dislikes} dislikes · ${songState.comments} comments",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(12.dp))
        if (loading) {
            Box(Modifier.fillMaxWidth().height(160.dp), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
        } else if (comments.isEmpty()) {
            EmptyText("No comments yet. Start the conversation.")
        } else {
            LazyColumn(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(comments, key = { it.id }) { comment ->
                    CommentRow(
                        comment = comment,
                        onLike = {
                            scope.launch {
                                if (AlloApi.likeComment(token, comment.id, !comment.likedByMe) is AlloApi.Result.Ok) {
                                    reload()
                                }
                            }
                        },
                        onReply = { replyTo = comment },
                        onDelete = {
                            scope.launch {
                                if (AlloApi.deleteComment(token, comment.id) is AlloApi.Result.Ok) reload()
                            }
                        },
                        onReport = {
                            scope.launch {
                                AlloApi.reportComment(token, comment.id, "user report")
                                error = "Reported. Thank you."
                            }
                        },
                    )
                }
            }
        }
        error?.let {
            Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
        }
        replyTo?.let { parent ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "Replying to ${parent.userName}",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.weight(1f),
                )
                TextButton(onClick = { replyTo = null }) { Text("Cancel") }
            }
        }
        Row(
            modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            OutlinedTextField(
                value = draft,
                onValueChange = { if (it.length <= 1000) draft = it },
                modifier = Modifier.weight(1f),
                placeholder = { Text(if (replyTo != null) "Write a reply" else "Add a comment") },
                maxLines = 3,
            )
            Spacer(Modifier.width(8.dp))
            Button(
                enabled = draft.isNotBlank() && !sending,
                onClick = {
                    sending = true
                    scope.launch {
                        when (val r = AlloApi.postComment(
                            token = token,
                            songKey = song.songKey,
                            body = draft.trim(),
                            parentId = replyTo?.id,
                            title = song.title,
                            artist = song.artist,
                            artUrl = song.artUrl,
                        )) {
                            is AlloApi.Result.Ok -> {
                                draft = ""
                                replyTo = null
                                reload()
                            }
                            is AlloApi.Result.Err -> error = r.message
                        }
                        sending = false
                    }
                },
            ) { Text(if (sending) "…" else "Send") }
        }
    }
}

@Composable
private fun CommentRow(
    comment: SocialComment,
    onLike: () -> Unit,
    onReply: () -> Unit,
    onDelete: () -> Unit,
    onReport: () -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(comment.userName, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold)
                Spacer(Modifier.width(8.dp))
                Text(
                    prettyTime(comment.createdAt),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.weight(1f),
                )
                if (comment.mine) {
                    IconButton(onClick = onDelete, modifier = Modifier.size(30.dp)) {
                        Icon(Icons.Rounded.Delete, contentDescription = "Delete", modifier = Modifier.size(16.dp))
                    }
                }
            }
            Text(comment.body, style = MaterialTheme.typography.bodyMedium)
            Row(verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = onLike) {
                    Icon(
                        if (comment.likedByMe) Icons.Rounded.Favorite else Icons.Rounded.FavoriteBorder,
                        contentDescription = null,
                        modifier = Modifier.size(16.dp),
                    )
                    Spacer(Modifier.width(4.dp))
                    Text(comment.likes.toString())
                }
                TextButton(onClick = onReply) {
                    Icon(Icons.Rounded.Reply, contentDescription = null, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(4.dp))
                    Text("Reply")
                }
                if (!comment.mine) {
                    IconButton(onClick = onReport, modifier = Modifier.size(30.dp)) {
                        Icon(Icons.Rounded.Flag, contentDescription = "Report", modifier = Modifier.size(16.dp))
                    }
                }
            }
        }
    }
}

@Composable
private fun Cover(url: String, size: androidx.compose.ui.unit.Dp) {
    if (url.isNotBlank()) {
        AsyncImage(
            model = url,
            contentDescription = null,
            contentScale = ContentScale.Crop,
            modifier = Modifier.size(size).clip(RoundedCornerShape(10.dp)),
        )
    } else {
        Box(
            modifier = Modifier
                .size(size)
                .clip(RoundedCornerShape(10.dp))
                .background(MaterialTheme.colorScheme.surfaceVariant),
            contentAlignment = Alignment.Center,
        ) {
            Text("♪", style = MaterialTheme.typography.titleMedium)
        }
    }
}

@Composable
private fun EmptyText(message: String) {
    Box(
        modifier = Modifier.fillMaxWidth().padding(28.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            message,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

private fun prettyTime(raw: String): String = runCatching {
    LocalDateTime.parse(raw.replace(' ', 'T'))
        .format(DateTimeFormatter.ofPattern("d MMM, HH:mm"))
}.getOrDefault(raw)
