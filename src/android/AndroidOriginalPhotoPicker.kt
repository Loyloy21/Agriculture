package expo.modules.agrireach

import android.Manifest
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ApplicationInfo
import android.graphics.BitmapFactory
import android.media.ExifInterface
import android.net.Uri
import android.os.Build
import android.provider.DocumentsContract
import android.provider.MediaStore
import android.content.ContentUris
import android.util.Log
import androidx.core.content.ContextCompat
import expo.modules.kotlin.Promise
import expo.modules.kotlin.activityresult.AppContextActivityResultContract
import expo.modules.kotlin.activityresult.AppContextActivityResultLauncher
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import java.io.File
import java.util.UUID

/** Reads only URIs explicitly returned by Android's system image picker. */
class AndroidOriginalPhotoPicker : Module() {
  private lateinit var picker: AppContextActivityResultLauncher<String, List<Uri>>
  private var pending: Promise? = null
  private var pendingLimit = 1

  override fun definition() = ModuleDefinition {
    Name("AndroidOriginalPhotoPicker")

    // Check the installed APK's manifest, not Expo/Metro JS configuration: a
    // Metro update cannot add a missing native Maps SDK key to an existing APK.
    Function("hasGoogleMapsApiKey") {
      val context = appContext.reactContext
      if (context == null) false else try {
        val info = context.packageManager.getApplicationInfo(context.packageName, PackageManager.GET_META_DATA)
        !info.metaData?.getString("com.google.android.geo.API_KEY").isNullOrBlank()
      } catch (_: Exception) {
        false
      }
    }

    // Explicitly target the installed Google Maps app. Older APKs lack this
    // function; JavaScript falls back to the Android geo: intent in that case.
    Function("openGoogleMapsPoint") { latitude: Double, longitude: Double ->
      val context = appContext.reactContext
      if (context == null || !latitude.isFinite() || !longitude.isFinite() ||
        latitude !in -90.0..90.0 || longitude !in -180.0..180.0 ||
        (latitude == 0.0 && longitude == 0.0)) false else try {
        val point = "$latitude,$longitude"
        val intent = Intent(Intent.ACTION_VIEW, Uri.parse("geo:$point?q=$point")).apply {
          setPackage("com.google.android.apps.maps")
          addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(intent)
        true
      } catch (_: ActivityNotFoundException) {
        false
      } catch (_: SecurityException) {
        false
      }
    }

    RegisterActivityContracts {
      picker = registerForActivityResult(OriginalPhotoContract())
    }

    AsyncFunction("pickOriginalPhotos") { limit: Int, promise: Promise ->
      if (pending != null) {
        promise.reject("PICKER_BUSY", "The Android photo picker is already open", null)
      } else {
        pendingLimit = limit.coerceIn(1, 10)
        pending = promise
        try {
          picker.launch("image/*") { uris ->
            val waiting = pending
            pending = null
            val context = appContext.reactContext
            if (waiting != null && context != null) {
              CoroutineScope(Dispatchers.IO).launch {
                try {
                  waiting.resolve(uris.take(pendingLimit).map { readSelected(context, it) })
                } catch (error: Exception) {
                  waiting.reject("PHOTO_READ_FAILED", "Selected photo could not be opened", error)
                }
              }
            } else waiting?.reject("NO_CONTEXT", "Android activity is unavailable", null)
          }
        } catch (error: Exception) {
          pending = null
          promise.reject("PICKER_UNAVAILABLE", "Android could not open the image picker", error)
        }
      }
    }
  }

  private class OriginalPhotoContract : AppContextActivityResultContract<String, List<Uri>> {
    override fun createIntent(context: Context, input: String): Intent =
      // DocumentsUI exposes the selected media document ID when backed by MediaStore.
      // ACTION_GET_CONTENT often routes through a photo-picker cache with no usable ID.
      Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
        type = input
        addCategory(Intent.CATEGORY_OPENABLE)
        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true)
      }

    override fun parseResult(input: String, resultCode: Int, intent: Intent?): List<Uri> {
      if (resultCode != Activity.RESULT_OK || intent == null) return emptyList()
      val selected = mutableListOf<Uri>()
      intent.data?.let(selected::add)
      intent.clipData?.let { clip ->
        for (index in 0 until clip.itemCount) selected.add(clip.getItemAt(index).uri)
      }
      return selected.distinct()
    }
  }

  private fun readSelected(context: android.content.Context, selected: Uri): Map<String, Any?> {
    val resolver = context.contentResolver
    val locationGranted = Build.VERSION.SDK_INT < 29 ||
      ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_MEDIA_LOCATION) == PackageManager.PERMISSION_GRANTED
    var gpsState = if (locationGranted) "no_gps" else "media_location_permission_denied"
    var latitude: Double? = null
    var longitude: Double? = null
    val mediaUri = when {
      selected.authority == MediaStore.AUTHORITY && selected.pathSegments.firstOrNull() != "picker" -> selected
      DocumentsContract.isDocumentUri(context, selected) -> {
        val documentId = try { DocumentsContract.getDocumentId(selected) } catch (_: Exception) { null }
        val parts = documentId?.split(':', limit = 2)
        if (parts?.size == 2 && parts[0] == "image" && parts[1].all(Char::isDigit)) {
          try { ContentUris.withAppendedId(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, parts[1].toLong()) }
          catch (_: Exception) { null }
        } else null
      }
      else -> null
    }
    val debugBuild = (context.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0
    if (debugBuild) Log.i("AgriReachPhotoGPS", "selectedAuthority=${selected.authority} exactMediaId=${mediaUri != null} mediaLocationGranted=$locationGranted")
    if (locationGranted) {
      if (mediaUri == null) {
        gpsState = "selected_uri_has_no_exact_media_id"
      } else try {
        val original = if (Build.VERSION.SDK_INT >= 29) MediaStore.setRequireOriginal(mediaUri) else mediaUri
        resolver.openFileDescriptor(original, "r").use { descriptor ->
          if (descriptor == null) throw IllegalStateException("Original stream unavailable")
          val exif = ExifInterface(descriptor.fileDescriptor)
          val latitudeRef = exif.getAttribute(ExifInterface.TAG_GPS_LATITUDE_REF)
          val longitudeRef = exif.getAttribute(ExifInterface.TAG_GPS_LONGITUDE_REF)
          val coordinates = FloatArray(2)
          gpsState = if (latitudeRef !in listOf("N", "S") || longitudeRef !in listOf("E", "W")) {
            "missing_or_invalid_gps_references"
          } else if (!exif.getLatLong(coordinates) || !coordinates[0].isFinite() || !coordinates[1].isFinite() ||
            (coordinates[0] == 0f && coordinates[1] == 0f) ||
            coordinates[0] !in -90f..90f || coordinates[1] !in -180f..180f) {
            "missing_or_invalid_gps"
          } else {
            latitude = coordinates[0].toDouble()
            longitude = coordinates[1].toDouble()
            "valid_original_exif"
          }
        }
      } catch (error: SecurityException) {
        gpsState = "original_permission_denied"
      } catch (error: UnsupportedOperationException) {
        gpsState = "original_not_offered_by_provider"
      } catch (error: Exception) {
        // Never call a redacted copy's tags photo GPS if original access failed.
        gpsState = "original_content_unavailable"
      }
    }
    if (debugBuild) Log.i("AgriReachPhotoGPS", "originalGpsState=$gpsState")
    val mime = resolver.getType(selected) ?: "image/jpeg"
    val suffix = if (mime == "image/heic" || mime == "image/heif") ".heic" else ".jpg"
    val file = File(context.cacheDir, "agrireach-selected-" + UUID.randomUUID() + suffix)
    resolver.openInputStream(selected).use { input ->
      if (input == null) throw IllegalStateException("Selected photo stream unavailable")
      file.outputStream().use { output -> input.copyTo(output) }
    }
    val dimensions = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeFile(file.absolutePath, dimensions)
    return mapOf(
      "uri" to Uri.fromFile(file).toString(), "width" to dimensions.outWidth,
      "height" to dimensions.outHeight, "mimeType" to mime,
      "selectedAuthority" to (selected.authority ?: "none"), "exactMediaId" to (mediaUri != null),
      "gpsState" to gpsState, "latitude" to latitude, "longitude" to longitude
    )
  }
}
