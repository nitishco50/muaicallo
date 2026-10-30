package com.music.bitchord.allo

/** A regional/language music scene offered during setup. */
data class AlloRegion(val id: String, val label: String)

/** One curated song used to seed artist preferences; no playback ids needed. */
data class AlloSong(val title: String, val artist: String, val region: String)

/**
 * Curated first-run choices.
 *
 * The setup wizard has to ask in the listener's own terms, and the YouTube
 * Music feed used by the app does not expose a per-track genre to build that
 * question from. These short lists are deliberately curated rather than
 * exhaustive: enough to establish a starting taste profile, not a catalogue.
 */
object AlloCatalog {

    val regions: List<AlloRegion> = listOf(
        AlloRegion("bollywood", "Bollywood / Hindi"),
        AlloRegion("punjabi", "Punjabi"),
        AlloRegion("tamil", "Tamil"),
        AlloRegion("telugu", "Telugu"),
        AlloRegion("malayalam", "Malayalam"),
        AlloRegion("kannada", "Kannada"),
        AlloRegion("bengali", "Bengali"),
        AlloRegion("marathi", "Marathi"),
        AlloRegion("gujarati", "Gujarati"),
        AlloRegion("bhojpuri", "Bhojpuri"),
        AlloRegion("sufi", "Sufi / Urdu"),
        AlloRegion("indie", "Indie"),
        AlloRegion("english", "English / Pop"),
        AlloRegion("kpop", "K-Pop"),
        AlloRegion("edm", "EDM"),
        AlloRegion("devotional", "Devotional"),
        AlloRegion("classical", "Classical"),
        AlloRegion("hiphop", "Hip-Hop / Rap"),
    )

    val songs: List<AlloSong> = listOf(
        AlloSong("Kesariya", "Arijit Singh", "bollywood"),
        AlloSong("Tum Hi Ho", "Arijit Singh", "bollywood"),
        AlloSong("Channa Mereya", "Arijit Singh", "bollywood"),
        AlloSong("Kal Ho Naa Ho", "Sonu Nigam", "bollywood"),
        AlloSong("Agar Tum Saath Ho", "Alka Yagnik", "bollywood"),
        AlloSong("Tujh Mein Rab Dikhta Hai", "Roop Kumar Rathod", "bollywood"),
        AlloSong("Lover", "Diljit Dosanjh", "punjabi"),
        AlloSong("295", "Sidhu Moose Wala", "punjabi"),
        AlloSong("Excuses", "AP Dhillon", "punjabi"),
        AlloSong("Tauba Tauba", "Karan Aujla", "punjabi"),
        AlloSong("Brown Munde", "AP Dhillon", "punjabi"),
        AlloSong("GOAT", "Diljit Dosanjh", "punjabi"),
        AlloSong("Rowdy Baby", "Dhanush", "tamil"),
        AlloSong("Arabic Kuthu", "Anirudh Ravichander", "tamil"),
        AlloSong("Vaseegara", "Bombay Jayashri", "tamil"),
        AlloSong("Nenjukkule", "A.R. Rahman", "tamil"),
        AlloSong("Enjoy Enjaami", "Dhee", "tamil"),
        AlloSong("Vaathi Coming", "Anirudh Ravichander", "tamil"),
        AlloSong("Butta Bomma", "Armaan Malik", "telugu"),
        AlloSong("Srivalli", "Sid Sriram", "telugu"),
        AlloSong("Samajavaragamana", "Sid Sriram", "telugu"),
        AlloSong("Naatu Naatu", "Rahul Sipligunj", "telugu"),
        AlloSong("Oo Antava", "Indravathi Chauhan", "telugu"),
        AlloSong("Ramuloo Ramulaa", "Anurag Kulkarni", "telugu"),
        AlloSong("Manavaalan Thug", "Sushin Shyam", "malayalam"),
        AlloSong("Darshana", "Hesham Abdul Wahab", "malayalam"),
        AlloSong("Kannil Kannil", "K.S. Chithra", "malayalam"),
        AlloSong("Jeevamshamayi", "K.J. Yesudas", "malayalam"),
        AlloSong("Belageddu", "Vijay Prakash", "kannada"),
        AlloSong("Anisuthide", "Sonu Nigam", "kannada"),
        AlloSong("Ninnindale", "Sanjith Hegde", "kannada"),
        AlloSong("Yaare Koogadali", "Puneeth Rajkumar", "kannada"),
        AlloSong("Ami Je Tomar", "Shreya Ghoshal", "bengali"),
        AlloSong("Bojhena Shey Bojhena", "Arijit Singh", "bengali"),
        AlloSong("Alo Alo", "Anupam Roy", "bengali"),
        AlloSong("Mon Majhi Re", "Rupam Islam", "bengali"),
        AlloSong("Zingaat", "Ajay-Atul", "marathi"),
        AlloSong("Mala Ved Lagale", "Swapnil Bandodkar", "marathi"),
        AlloSong("Jiv Dangla", "Bela Shende", "marathi"),
        AlloSong("Sairat Zaala Ji", "Ketaki Mategaonkar", "marathi"),
        AlloSong("Chogada", "Darshan Raval", "gujarati"),
        AlloSong("Khalasi", "Aditya Gadhvi", "gujarati"),
        AlloSong("Rangilo Maro Dholna", "Falguni Pathak", "gujarati"),
        AlloSong("Mor Bani Thanghat Kare", "Osman Mir", "gujarati"),
        AlloSong("Lollipop Lagelu", "Pawan Singh", "bhojpuri"),
        AlloSong("Hariyar Nagariya", "Khesari Lal Yadav", "bhojpuri"),
        AlloSong("Ae Ho Piya", "Shilpa Rao", "bhojpuri"),
        AlloSong("Tohre Bin Kaisan Ba", "Nirahua", "bhojpuri"),
        AlloSong("Tere Bin", "Rahat Fateh Ali Khan", "sufi"),
        AlloSong("Tajdar-e-Haram", "Atif Aslam", "sufi"),
        AlloSong("Mere Rashke Qamar", "Nusrat Fateh Ali Khan", "sufi"),
        AlloSong("Kun Faya Kun", "A.R. Rahman", "sufi"),
        AlloSong("Kasoor", "Prateek Kuhad", "indie"),
        AlloSong("Raabta", "Anuv Jain", "indie"),
        AlloSong("Liggi", "Ritviz", "indie"),
        AlloSong("Aaoge Tum Kabhi", "The Local Train", "indie"),
        AlloSong("Perfect", "Ed Sheeran", "english"),
        AlloSong("Blinding Lights", "The Weeknd", "english"),
        AlloSong("Shape of You", "Ed Sheeran", "english"),
        AlloSong("Someone Like You", "Adele", "english"),
        AlloSong("Dynamite", "BTS", "kpop"),
        AlloSong("How You Like That", "BLACKPINK", "kpop"),
        AlloSong("God's Menu", "Stray Kids", "kpop"),
        AlloSong("Super Shy", "NewJeans", "kpop"),
        AlloSong("Faded", "Alan Walker", "edm"),
        AlloSong("Animals", "Martin Garrix", "edm"),
        AlloSong("Titanium", "David Guetta", "edm"),
        AlloSong("Alone", "Marshmello", "edm"),
        AlloSong("Achyutam Keshavam", "Anup Jalota", "devotional"),
        AlloSong("Vaishnav Jan To", "Jagjit Singh", "devotional"),
        AlloSong("Hanuman Chalisa", "Gulshan Kumar", "devotional"),
        AlloSong("Om Namah Shivaya", "S.P. Balasubrahmanyam", "devotional"),
        AlloSong("Raag Yaman", "Ravi Shankar", "classical"),
        AlloSong("Raag Bhairavi", "Hariprasad Chaurasia", "classical"),
        AlloSong("Taal Rupak", "Zakir Hussain", "classical"),
        AlloSong("Bhimpalasi", "Bhimsen Joshi", "classical"),
        AlloSong("Apna Time Aayega", "DIVINE", "hiphop"),
        AlloSong("Mere Gully Mein", "DIVINE", "hiphop"),
        AlloSong("Machayenge", "Emiway Bantai", "hiphop"),
        AlloSong("She Move It Like", "Badshah", "hiphop"),
    )

    private val artistsByRegion: Map<String, List<String>> = mapOf(
        "bollywood" to listOf(
            "Arijit Singh", "Shreya Ghoshal", "Neha Kakkar", "Atif Aslam", "Pritam",
            "A.R. Rahman", "Sonu Nigam", "Jubin Nautiyal", "Darshan Raval", "Alka Yagnik",
        ),
        "punjabi" to listOf(
            "Diljit Dosanjh", "Sidhu Moose Wala", "AP Dhillon", "Karan Aujla",
            "Guru Randhawa", "Badshah", "Shubh", "Ammy Virk",
        ),
        "tamil" to listOf(
            "Anirudh Ravichander", "A.R. Rahman", "Sid Sriram", "Harris Jayaraj",
            "Yuvan Shankar Raja", "G.V. Prakash", "Dhanush", "Ilaiyaraaja",
        ),
        "telugu" to listOf(
            "Devi Sri Prasad", "S.S. Thaman", "Sid Sriram", "Anurag Kulkarni",
            "Mangli", "M.M. Keeravani", "Armaan Malik", "Rahul Sipligunj",
        ),
        "malayalam" to listOf(
            "Sushin Shyam", "Hesham Abdul Wahab", "K.S. Chithra", "K.J. Yesudas",
            "Vineeth Sreenivasan", "Shreya Ghoshal", "Shaan Rahman", "M. Jayachandran",
        ),
        "kannada" to listOf(
            "Vijay Prakash", "Sanjith Hegde", "Arjun Janya", "Sonu Nigam",
            "Puneeth Rajkumar", "Raghu Dixit", "V. Harikrishna", "Anuradha Bhat",
        ),
        "bengali" to listOf(
            "Arijit Singh", "Shreya Ghoshal", "Anupam Roy", "Jeet Gannguli",
            "Rupam Islam", "Nachiketa", "Monali Thakur", "Arijit Singh",
        ),
        "marathi" to listOf(
            "Ajay-Atul", "Swapnil Bandodkar", "Bela Shende", "Ketaki Mategaonkar",
            "Shankar Mahadevan", "Anandi Joshi", "Rohit Raut", "Vaishali Samant",
        ),
        "gujarati" to listOf(
            "Falguni Pathak", "Osman Mir", "Jignesh Kaviraj", "Kinjal Dave",
            "Aditya Gadhvi", "Darshan Raval", "Sachin-Jigar", "Aishwarya Majmudar",
        ),
        "bhojpuri" to listOf(
            "Pawan Singh", "Khesari Lal Yadav", "Nirahua", "Ankush Raja",
            "Shilpa Rao", "Kalpana Patowary", "Ritesh Pandey", "Neelkamal Singh",
        ),
        "sufi" to listOf(
            "Rahat Fateh Ali Khan", "Nusrat Fateh Ali Khan", "Atif Aslam",
            "Abida Parveen", "Javed Ali", "Kailash Kher", "A.R. Rahman", "Amit Trivedi",
        ),
        "indie" to listOf(
            "Prateek Kuhad", "Ritviz", "Anuv Jain", "When Chai Met Toast",
            "The Local Train", "Peter Cat Recording Co.", "Parekh & Singh", "Lifafa",
        ),
        "english" to listOf(
            "Ed Sheeran", "Taylor Swift", "The Weeknd", "Coldplay", "Bruno Mars",
            "Billie Eilish", "Drake", "Adele",
        ),
        "kpop" to listOf(
            "BTS", "BLACKPINK", "TWICE", "Stray Kids", "SEVENTEEN",
            "NewJeans", "EXO", "IU",
        ),
        "edm" to listOf(
            "Martin Garrix", "David Guetta", "Alan Walker", "Marshmello",
            "Calvin Harris", "Zedd", "Kygo", "Avicii",
        ),
        "devotional" to listOf(
            "Anup Jalota", "Jagjit Singh", "M.S. Subbulakshmi", "S.P. Balasubrahmanyam",
            "Hans Raj Hans", "Gulshan Kumar", "Anuradha Paudwal", "Kishori Amonkar",
        ),
        "classical" to listOf(
            "Ravi Shankar", "Hariprasad Chaurasia", "Zakir Hussain", "M.S. Subbulakshmi",
            "Bhimsen Joshi", "Kishori Amonkar", "Amjad Ali Khan", "Shivkumar Sharma",
        ),
        "hiphop" to listOf(
            "DIVINE", "Raftaar", "Emiway Bantai", "KR\$NA", "Badshah",
            "Yo Yo Honey Singh", "Ikka", "King",
        ),
    )

    /** Song artists first, then the rest of the chosen scenes, deduplicated. */
    fun suggestedArtists(regions: Set<String>, selectedSongs: Set<AlloSong>): List<String> {
        val out = LinkedHashSet<String>()
        selectedSongs.forEach { song ->
            if (song.artist.isNotBlank()) out += song.artist
        }
        regions.forEach { region ->
            artistsByRegion[region]?.let { out += it }
        }
        return out.toList()
    }

    fun songsFor(regions: Set<String>): List<AlloSong> =
        if (regions.isEmpty()) emptyList() else songs.filter { it.region in regions }

    fun regionLabel(id: String): String = regions.firstOrNull { it.id == id }?.label ?: id
}
