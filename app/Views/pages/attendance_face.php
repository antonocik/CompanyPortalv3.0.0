<?php
declare(strict_types=1);

use App\Config\App;
use App\Core\Auth;

require __DIR__ . '/../layouts/admin_header.php';

$record   = $record ?? null;
$enrolled = $enrolled ?? null;
$enrolledDescriptor = $enrolled ? json_decode($enrolled, true) : null;
?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <h5 class="card-title mb-0"><i class="ti ti-face-id me-2"></i>Absensi Wajah (Face Recognition)</h5>
      </div>
      <div class="card-body">
        <div class="position-relative" style="max-width: 480px;">
          <video id="faceVideo" autoplay playsinline muted style="width:100%; border-radius:8px; background:#000;"></video>
          <canvas id="faceOverlay" style="position:absolute; top:0; left:0; width:100%; height:100%;"></canvas>
        </div>
        <div class="mt-3 d-flex gap-2 flex-wrap">
          <button id="btnCapture" class="btn btn-primary" type="button">
            <i class="ti ti-camera me-1"></i> Absen dengan Wajah
          </button>
          <button id="btnEnroll" class="btn btn-outline-secondary" type="button">
            <i class="ti ti-user-plus me-1"></i> Daftarkan Wajah
          </button>
          <span id="faceStatus" class="align-self-center text-muted small">Memuat model...</span>
        </div>
        <div class="mt-3">
          <label class="form-label small text-muted mb-1">Atau gunakan file foto (jika kamera tidak tersedia):</label>
          <div class="d-flex gap-2 flex-wrap">
            <input type="file" id="fileEnroll" accept="image/*" class="form-control form-control-sm" style="max-width:260px;">
            <button id="btnEnrollFile" class="btn btn-sm btn-outline-secondary" type="button">Daftar dari File</button>
            <input type="file" id="fileCheckin" accept="image/*" class="form-control form-control-sm" style="max-width:260px;">
            <button id="btnCheckinFile" class="btn btn-sm btn-primary" type="button">Absen dari File</button>
          </div>
        </div>
        <form id="faceForm" method="post" action="<?= url('/attendance/face/check-in'); ?>" class="d-none">
          <input type="hidden" name="csrf_token" value="<?= e(\App\Core\Csrf::token()); ?>">
          <input type="hidden" name="image" id="faceImage">
          <input type="hidden" name="descriptor" id="faceDescriptorCheckin">
        </form>
        <form id="enrollForm" method="post" action="<?= url('/attendance/face/enroll'); ?>" class="d-none">
          <input type="hidden" name="csrf_token" value="<?= e(\App\Core\Csrf::token()); ?>">
          <input type="hidden" name="descriptor" id="faceDescriptor">
          <input type="hidden" name="image" id="enrollImage">
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h5 class="card-title mb-0">Status Hari Ini</h5></div>
      <div class="card-body">
        <?php if ($record): ?>
          <p class="mb-1"><strong>Masuk:</strong> <?= e($record['check_in'] ?? '-'); ?></p>
          <p class="mb-1"><strong>Keluar:</strong> <?= e($record['check_out'] ?? '-'); ?></p>
          <p class="mb-0"><strong>Status:</strong> <?= e(\App\Models\AttendanceRecord::statusLabel($record['status'] ?? null)); ?></p>
          <?php if (!empty($record['face_image_id'])): ?>
            <p class="mt-2 mb-0 small text-muted">Foto wajah tersimpan (#<?= (int) $record['face_image_id']; ?>)</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-muted mb-0">Belum ada absensi hari ini.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="card mt-3">
      <div class="card-body small">
        <p class="mb-1"><strong>Status pendaftaran wajah:</strong>
          <?php if ($enrolled): ?>
            <span class="text-success">Sudah terdaftar</span>
          <?php else: ?>
            <span class="text-warning">Belum terdaftar</span>
          <?php endif; ?>
        </p>
        <p class="text-muted mb-0">
          <i class="ti ti-info-circle me-1"></i>
          Daftarkan wajah terlebih dahulu. Saat absen, sistem membandingkan wajah dengan data terdaftar.
        </p>
      </div>
    </div>
  </div>
</div>

<script src="<?= asset('vendor/face-api/face-api.min.js'); ?>"></script>
<script>
(async function () {
  const video = document.getElementById('faceVideo');
  const overlay = document.getElementById('faceOverlay');
  const btnCapture = document.getElementById('btnCapture');
  const btnEnroll = document.getElementById('btnEnroll');
  const status = document.getElementById('faceStatus');
  const faceForm = document.getElementById('faceForm');
  const enrollForm = document.getElementById('enrollForm');
  const imgInput = document.getElementById('faceImage');
  const descInput = document.getElementById('faceDescriptor');
  const descCheckin = document.getElementById('faceDescriptorCheckin');
  const enrollImg = document.getElementById('enrollImage');

  const enrolled = <?= $enrolledDescriptor ? json_encode($enrolledDescriptor) : 'null'; ?>;

  let modelsLoaded = false;
  try {
    const uri = '<?= url('/assets/vendor/face-api/weights'); ?>';
    await faceapi.nets.tinyFaceDetector.loadFromUri(uri);
    await faceapi.nets.faceLandmark68Net.loadFromUri(uri);
    await faceapi.nets.faceRecognitionNet.loadFromUri(uri);
    modelsLoaded = true;
    status.textContent = 'Model siap. Menyalakan kamera...';
  } catch (e) {
    status.textContent = 'Gagal memuat model: ' + (e.message || e) + '. Gunakan Absen/Daftar dari File.';
    console.error(e);
    return;
  }

  const opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 512, scoreThreshold: 0.3 });

  let stream = null;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
    video.srcObject = stream;
  } catch (e) {
    status.textContent = 'Kamera tidak dapat diakses: ' + (e.message || e) + '. Gunakan Absen/Daftar dari File.';
  }

  let currentDetection = null;
  if (stream) {
    video.addEventListener('loadedmetadata', function () {
      overlay.width = video.videoWidth;
      overlay.height = video.videoHeight;
      status.textContent = 'Kamera aktif. Posisikan wajah di tengah.';
      detectLoop();
    });
  }

  async function detectLoop() {
    if (!modelsLoaded || video.paused || video.ended) return;
    const detections = await faceapi.detectAllFaces(video, opts).withFaceLandmarks().withFaceDescriptors();
    const ctx = overlay.getContext('2d');
    ctx.clearRect(0, 0, overlay.width, overlay.height);
    if (detections.length === 1) {
      currentDetection = detections[0];
      const box = detections[0].box;
      ctx.strokeStyle = '#22c55e';
      ctx.lineWidth = 3;
      ctx.strokeRect(box.x, box.y, box.width, box.height);
      status.textContent = enrolled ? 'Wajah terdeteksi. Siap absen/verifikasi.' : 'Wajah terdeteksi. Daftarkan terlebih dahulu.';
    } else if (detections.length > 1) {
      currentDetection = null;
      status.textContent = 'Terdeteksi lebih dari satu wajah.';
    } else {
      currentDetection = null;
      status.textContent = 'Tidak ada wajah terdeteksi.';
    }
    requestAnimationFrame(detectLoop);
  }

  function captureImage() {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/png');
  }

  btnCapture.addEventListener('click', async function () {
    if (!stream) { status.textContent = 'Kamera tidak aktif. Gunakan Absen dari File.'; return; }
    if (!currentDetection) { status.textContent = 'Tidak ada wajah terdeteksi.'; return; }
    if (!enrolled) { status.textContent = 'Wajah belum didaftarkan. Daftarkan terlebih dahulu.'; return; }
    const dist = faceapi.euclideanDistance(currentDetection.descriptor, enrolled);
    if (dist > 0.6) { status.textContent = 'Wajah tidak cocok (jarak ' + dist.toFixed(2) + '). Absen ditolak.'; return; }
    btnCapture.disabled = true;
    status.textContent = 'Mengirim...';
    imgInput.value = captureImage();
    descCheckin.value = JSON.stringify(Array.from(currentDetection.descriptor));
    faceForm.submit();
  });

  btnEnroll.addEventListener('click', async function () {
    if (!stream) { status.textContent = 'Kamera tidak aktif. Gunakan Daftar dari File.'; return; }
    if (!currentDetection) { status.textContent = 'Tidak ada wajah terdeteksi.'; return; }
    btnEnroll.disabled = true;
    status.textContent = 'Mendaftarkan wajah...';
    descInput.value = JSON.stringify(Array.from(currentDetection.descriptor));
    enrollImg.value = captureImage();
    enrollForm.submit();
  });

  async function processFile(file, mode) {
    if (!file) { status.textContent = 'Pilih file foto terlebih dahulu.'; return; }
    if (!modelsLoaded) { status.textContent = 'Model belum siap.'; return; }
    status.textContent = 'Memproses foto...';
    const img = await faceapi.bufferToImage(file);
    const det = await faceapi.detectSingleFace(img, opts).withFaceLandmarks().withFaceDescriptors();
    if (!det) { status.textContent = 'Tidak ada wajah terdeteksi pada foto.'; return; }
    const canvas = document.createElement('canvas');
    canvas.width = img.width; canvas.height = img.height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0);
    const dataUrl = canvas.toDataURL('image/png');
    if (mode === 'enroll') {
      descInput.value = JSON.stringify(Array.from(det.descriptor));
      enrollImg.value = dataUrl;
      enrollForm.submit();
    } else {
      if (!enrolled) { status.textContent = 'Wajah belum didaftarkan. Daftarkan terlebih dahulu.'; return; }
      const dist = faceapi.euclideanDistance(det.descriptor, enrolled);
      if (dist > 0.6) { status.textContent = 'Wajah tidak cocok (jarak ' + dist.toFixed(2) + ').'; return; }
      imgInput.value = dataUrl;
      descCheckin.value = JSON.stringify(Array.from(det.descriptor));
      faceForm.submit();
    }
  }

  document.getElementById('btnEnrollFile').addEventListener('click', function () {
    processFile(document.getElementById('fileEnroll').files[0], 'enroll');
  });
  document.getElementById('btnCheckinFile').addEventListener('click', function () {
    processFile(document.getElementById('fileCheckin').files[0], 'checkin');
  });
})();
</script>

<?php require __DIR__ . '/../layouts/admin_footer.php'; ?>
PHP
echo created