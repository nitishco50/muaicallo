package com.music.bitchord.allo

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CloudDownload
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
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
import com.music.bitchord.ui.theme.BitChordTheme
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

/**
 * Sign in / register, and the account's sync controls.
 *
 * Its own activity so the settings sheet does not have to grow a navigation
 * stack for a screen most listeners open once. Everything it needs is behind
 * [AlloApi], and the token is written straight into the encrypted store.
 */
class AlloAccountActivity : ComponentActivity() {

    companion object {
        /** Set when the screen is the mandatory first-run gate, not a settings page. */
        const val EXTRA_GATE = "com.music.bitchord.allo.GATE"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        AlloSession.init(this)
        AlloEvents.init(this)
        AlloRemoteConfig.load(this)
        val gate = intent.getBooleanExtra(EXTRA_GATE, false)
        setContent {
            BitChordTheme(darkTheme = isSystemInDarkTheme()) {
                AlloAccountScreen(gateMode = gate, onClose = { finish() })
            }
        }
    }
}

private data class AuthPayload(
    val token: String,
    val email: String,
    val name: String,
    val userId: Long?,
    val uuid: String?,
)

private fun parseAuth(data: JsonObject): AuthPayload? {
    val token = data["token"]?.jsonPrimitive?.contentOrNull ?: return null
    val user = data["user"]?.takeIf { it is JsonObject }?.jsonObject
    return AuthPayload(
        token = token,
        email = user?.get("email")?.jsonPrimitive?.contentOrNull.orEmpty(),
        name = user?.get("name")?.jsonPrimitive?.contentOrNull.orEmpty(),
        userId = user?.get("id")?.jsonPrimitive?.contentOrNull?.toLongOrNull(),
        uuid = user?.get("uuid")?.jsonPrimitive?.contentOrNull,
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AlloAccountScreen(gateMode: Boolean, onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var signedIn by remember { mutableStateOf(AlloSession.isSignedIn) }
    var registerMode by remember { mutableStateOf(false) }
    var email by remember { mutableStateOf(AlloSession.email.orEmpty()) }
    var password by remember { mutableStateOf("") }
    var name by remember { mutableStateOf(AlloSession.name.orEmpty()) }
    var busy by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("allomusic account") },
                navigationIcon = {
                    IconButton(onClick = onClose) {
                        Icon(Icons.Rounded.Close, contentDescription = "Close")
                    }
                },
            )
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(20.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            if (signedIn) {
                Card(Modifier.fillMaxWidth()) {
                    Row(
                        modifier = Modifier.fillMaxWidth().padding(18.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            Icons.Rounded.Person,
                            contentDescription = null,
                            modifier = Modifier.size(40.dp),
                            tint = MaterialTheme.colorScheme.primary,
                        )
                        Spacer(Modifier.size(14.dp))
                        Column {
                            Text(
                                AlloSession.name?.takeIf { it.isNotBlank() } ?: "Listener",
                                style = MaterialTheme.typography.titleMedium,
                            )
                            Text(
                                AlloSession.email.orEmpty(),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }

                Text(
                    "Connected to ${AlloApi.BASE_URL}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Button(
                    modifier = Modifier.fillMaxWidth(),
                    onClick = {
                        context.startActivity(
                            Intent(context, AlloHubActivity::class.java),
                        )
                    },
                ) {
                    Text("History, likes & community")
                }

                Button(
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !busy,
                    onClick = {
                        scope.launch {
                            busy = true
                            error = null
                            message = null
                            val ok = AlloSync.pushSettings(context)
                            message = if (ok) "Settings backed up." else null
                            error = if (ok) null else "Could not back up to the server."
                            busy = false
                        }
                    },
                ) {
                    Icon(Icons.Rounded.CloudUpload, contentDescription = null)
                    Spacer(Modifier.size(8.dp))
                    Text("Back up settings")
                }

                OutlinedButton(
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !busy,
                    onClick = {
                        scope.launch {
                            busy = true
                            error = null
                            message = null
                            val ok = AlloSync.pullSettings(context)
                            message = if (ok) "Settings restored from your account." else null
                            error = if (ok) null else "Nothing to restore, or the server was unreachable."
                            busy = false
                        }
                    },
                ) {
                    Icon(Icons.Rounded.CloudDownload, contentDescription = null)
                    Spacer(Modifier.size(8.dp))
                    Text("Restore settings")
                }

                OutlinedButton(
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !busy,
                    onClick = {
                        scope.launch {
                            busy = true
                            val token = AlloSession.token
                            if (token != null) AlloApi.logout(token)
                            AlloSession.signOut()
                            signedIn = false
                            password = ""
                            message = "Signed out of this device."
                            error = null
                            busy = false
                        }
                    },
                ) {
                    Icon(Icons.Rounded.Logout, contentDescription = null)
                    Spacer(Modifier.size(8.dp))
                    Text("Sign out")
                }

                TextButton(
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !busy,
                    onClick = { confirmDelete = true },
                ) {
                    Icon(Icons.Rounded.DeleteForever, contentDescription = null, tint = MaterialTheme.colorScheme.error)
                    Spacer(Modifier.size(8.dp))
                    Text("Delete my account", color = MaterialTheme.colorScheme.error)
                }
            } else {
                Text(
                    if (registerMode) "Create your allomusic account" else "Sign in to allomusic",
                    style = MaterialTheme.typography.headlineSmall,
                )
                Text(
                    "Your settings, history and preferences stay in your own account on ${AlloApi.BASE_URL}.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                if (registerMode) {
                    OutlinedTextField(
                        value = name,
                        onValueChange = { name = it },
                        label = { Text("Name") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
                OutlinedTextField(
                    value = email,
                    onValueChange = { email = it },
                    label = { Text("Email") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text("Password") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth(),
                )

                Button(
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !busy && email.isNotBlank() && password.length >= 8,
                    onClick = {
                        scope.launch {
                            busy = true
                            error = null
                            message = null
                            val device = AlloSession.device(context)
                            val result = if (registerMode) {
                                AlloApi.register(email.trim(), password, name.trim(), device)
                            } else {
                                AlloApi.login(email.trim(), password, device)
                            }
                            when (result) {
                                is AlloApi.Result.Ok -> {
                                    val auth = parseAuth(result.data)
                                    if (auth == null) {
                                        error = "The server response was incomplete."
                                    } else {
                                        AlloSession.save(auth.token, auth.email, auth.name, auth.userId, auth.uuid)
                                        email = auth.email
                                        name = auth.name
                                        password = ""
                                        AlloSync.pushSettings(context)
                                        AlloEvents.flushSoon()
                                        signedIn = true
                                        message = if (registerMode) "Account created." else "Signed in."
                                        if (gateMode) onClose()
                                    }
                                }
                                is AlloApi.Result.Err -> error = result.message
                            }
                            busy = false
                        }
                    },
                ) {
                    Text(if (registerMode) "Create account" else "Sign in")
                }

                TextButton(
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !busy,
                    onClick = {
                        registerMode = !registerMode
                        error = null
                        message = null
                    },
                ) {
                    Text(if (registerMode) "I already have an account" else "Create a new account")
                }

            }

            if (busy) {
                Text("Working…", style = MaterialTheme.typography.bodySmall)
            }
            error?.let {
                Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
            }
            message?.let {
                Text(it, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.bodyMedium)
            }
            Spacer(Modifier.height(20.dp))
        }
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Delete account?") },
            text = { Text("This removes your account and every document stored on the server. It cannot be undone.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = false
                    scope.launch {
                        busy = true
                        val token = AlloSession.token
                        if (token != null) AlloApi.deleteAccount(token)
                        AlloSession.signOut()
                        signedIn = false
                        message = "Account deleted."
                        busy = false
                    }
                }) { Text("Delete") }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = false }) { Text("Cancel") }
            },
        )
    }
}
