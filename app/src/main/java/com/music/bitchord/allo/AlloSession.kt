package com.music.bitchord.allo

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import com.music.bitchord.BuildConfig
import com.music.bitchord.auth.EncryptedPrefs
import java.util.UUID

/**
 * The allomusic account on this device.
 *
 * The bearer token is a credential, so it lives in the same encrypted store the
 * YouTube cookie uses. The device id is not a credential and is kept beside it;
 * it exists so the panel can show "Pixel 8, last seen 2 minutes ago" and revoke
 * one phone without touching the others.
 */
object AlloSession {

    private const val PREFS = "allo_account"

    @Volatile private var prefs: SharedPreferences? = null

    @Volatile var token: String? = null
        private set

    @Volatile var email: String? = null
        private set

    @Volatile var name: String? = null
        private set

    /** Set once the welcome sheet has been answered either way. */
    @Volatile var gateDone: Boolean = false
        private set

    fun init(context: Context) {
        if (prefs != null) return
        synchronized(this) {
            if (prefs != null) return
            val store = EncryptedPrefs.open(
                context.applicationContext,
                PREFS,
                "allo_account_plain",
            )
            prefs = store
            token = store.getString("token", null)
            email = store.getString("email", null)
            name = store.getString("name", null)
            gateDone = store.getBoolean("gate_done", false)
        }
    }

    val isSignedIn: Boolean get() = !token.isNullOrBlank()

    fun save(token: String, email: String, name: String) {
        this.token = token
        this.email = email
        this.name = name
        gateDone = true
        prefs?.edit()
            ?.putString("token", token)
            ?.putString("email", email)
            ?.putString("name", name)
            ?.putBoolean("gate_done", true)
            ?.apply()
    }

    fun updateProfile(email: String, name: String) {
        this.email = email
        this.name = name
        prefs?.edit()?.putString("email", email)?.putString("name", name)?.apply()
    }

    fun signOut() {
        token = null
        email = null
        name = null
        prefs?.edit()?.remove("token")?.remove("email")?.remove("name")?.apply()
    }

    fun markGateDone() {
        gateDone = true
        prefs?.edit()?.putBoolean("gate_done", true)?.apply()
    }

    fun device(context: Context): AlloApi.Device {
        val store = prefs ?: run {
            init(context)
            prefs
        }
        var id = store?.getString("device_id", null)
        if (id.isNullOrBlank()) {
            id = UUID.randomUUID().toString()
            store?.edit()?.putString("device_id", id)?.apply()
        }
        return AlloApi.Device(
            id = id,
            model = listOfNotNull(Build.MANUFACTURER, Build.MODEL).joinToString(" ").trim(),
            sdk = Build.VERSION.SDK_INT.toString(),
            appVersion = BuildConfig.VERSION_NAME,
        )
    }
}
