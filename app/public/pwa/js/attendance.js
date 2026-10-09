/**
 * Attendance card: honest notice + consent, phone approval, and punch in / out with the location at
 * that moment and a live camera photo. Punches are queued locally and uploaded through the sync
 * outbox, so they work offline; the server decides valid / flagged / rejected and says why.
 */

/** Id of this app session: the server can date a punch from the monotonic clock only within one session. */
export const BOOT_ID = crypto.randomUUID();

/** The device clock sent with every upload (see sync-engine `clock`). */
export function deviceClock() {
  return { boot_id: BOOT_ID, monotonic_ms: Math.round(performance.now()), wall_time: new Date().toISOString() };
}

const STATUS_TEXT = { valid: 'Accepted', flagged: 'Accepted for review', rejected: 'Not accepted' };

/**
 * @param {{ store: any, api: any, engine: any, account: any }} ctx
 */
export async function renderAttendance(ctx) {
  const card = document.getElementById('attendance');
  if (!card) return;
  const can = (ctx.account.permissions || []).includes('hr.attendance.punch');
  card.hidden = !can;
  if (!can) return;

  let me = await ctx.store.getMeta('attendance_me', null);
  if (navigator.onLine) {
    try {
      me = await ctx.api.attendanceMe();
      await ctx.store.setMeta('attendance_me', me);
    } catch {
      // Offline or server busy: the last known state is used.
    }
  }
  const info = card.querySelector('.info');
  const notice = card.querySelector('.notice');
  const actions = card.querySelector('.actions');
  info.textContent = '';
  notice.hidden = true;
  actions.hidden = true;

  if (!me) {
    info.textContent = 'Connect to the internet once to set up attendance on this phone.';
    return;
  }
  if (!me.employee) {
    info.textContent = me.message || 'This login is not linked to an employee record. Ask HR.';
    return;
  }
  info.textContent = `${me.employee.name} (${me.employee.code})`;

  if (!me.notice.accepted) {
    notice.hidden = false;
    notice.querySelector('.text').textContent = me.notice.text;
    notice.querySelector('.agree').onclick = async () => {
      try {
        await ctx.api.attendanceConsent(me.notice.version);
        await renderAttendance(ctx);
      } catch {
        alert('Accepting the notice needs the internet. Try again when online.');
      }
    };
    return;
  }

  const device = card.querySelector('.device');
  const status = me.device.status;
  device.textContent = {
    approved: 'This phone is approved for attendance.',
    pending: 'Waiting for HR to approve this phone. Punches are kept, but marked for review until then.',
    rejected: 'HR did not approve this phone. Ask HR.',
    revoked: 'This phone was replaced by another one. Ask HR.',
    not_requested: 'This phone is not yet approved for attendance.',
  }[status] || status;
  const ask = card.querySelector('.request-device');
  ask.hidden = !['not_requested', 'rejected', 'revoked'].includes(status);
  ask.onclick = async () => {
    try {
      await ctx.api.attendanceRequestDevice();
      await renderAttendance(ctx);
    } catch {
      alert('Asking for approval needs the internet.');
    }
  };

  actions.hidden = false;
  card.querySelector('.punch-in').onclick = () => punch(ctx, 'in', me.require_selfie);
  card.querySelector('.punch-out').onclick = () => punch(ctx, 'out', me.require_selfie);
  await renderPunches(ctx, me);
}

async function renderPunches(ctx, me) {
  const list = document.getElementById('punches');
  // Uploaded punches come back in "today" with the server's verdict; queued ones are shown as such.
  const pending = (await ctx.store.listEvents()).filter((e) => e.type === 'attendance.punch' && e.account_id === ctx.engine.accountId);
  const rows = [
    ...(me.today || []).map((p) => ({ at: p.at, kind: p.kind, text: `${STATUS_TEXT[p.status] || p.status}${p.messages?.length ? ` — ${p.messages.join('; ')}` : ''}`, status: p.status })),
    ...pending.map((e) => ({ at: e.payload.device_time, kind: e.payload.kind, text: 'Saved on this phone, not uploaded yet', status: 'pending' })),
  ].sort((a, b) => a.at.localeCompare(b.at));
  list.replaceChildren(...rows.map((r) => {
    const li = document.createElement('li');
    li.className = r.status === 'rejected' ? 'danger' : r.status === 'flagged' ? 'warning' : '';
    li.textContent = `${new Date(r.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · ${r.kind === 'in' ? 'In' : 'Out'} · ${r.text}`;
    return li;
  }));
}

async function punch(ctx, kind, requireSelfie) {
  const takenAt = new Date().toISOString();
  const monotonic = Math.round(performance.now());

  let position = null;
  try {
    position = await currentPosition();
  } catch (e) {
    if (!confirm(`Your location could not be read (${e.message || e}). Punch anyway? It will be marked for review.`)) return;
  }

  let photo = null;
  try {
    photo = await takePhoto();
  } catch (e) {
    const why = e.message || String(e);
    if (requireSelfie && !confirm(`No photo was taken (${why}). Punch anyway? It will be marked for review.`)) return;
  }
  if (photo) await ctx.engine.addFile(photo.sha256, photo.mime, photo.base64);

  await ctx.engine.append('attendance.punch', {
    kind, device_time: takenAt, boot_id: BOOT_ID, monotonic_ms: monotonic, source: 'pwa',
    lat: position?.coords.latitude ?? null, lng: position?.coords.longitude ?? null,
    accuracy_m: position ? Math.round(position.coords.accuracy) : null,
    selfie_sha256: photo?.sha256 ?? null,
  });
  if (navigator.onLine) await ctx.engine.sync();
  await renderAttendance(ctx);
}

function currentPosition() {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) return reject(new Error('no GPS on this device'));
    navigator.geolocation.getCurrentPosition(resolve, (err) => reject(new Error(err.message || 'permission denied')), {
      enableHighAccuracy: true, timeout: 20_000, maximumAge: 0,
    });
  });
}

/** Live frame from the front camera (no gallery uploads), scaled down to keep uploads small. */
async function takePhoto() {
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('no camera access in this browser');
  const dialog = document.getElementById('camera');
  const video = dialog.querySelector('video');
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 } }, audio: false });
  try {
    video.srcObject = stream;
    await video.play();
    dialog.showModal();
    await new Promise((resolve, reject) => {
      dialog.querySelector('.capture').onclick = resolve;
      dialog.querySelector('.cancel').onclick = () => reject(new Error('cancelled'));
    });
    const scale = Math.min(1, 480 / (video.videoWidth || 480));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round((video.videoWidth || 480) * scale);
    canvas.height = Math.round((video.videoHeight || 360) * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.7));
    const bytes = new Uint8Array(await blob.arrayBuffer());
    return { mime: 'image/jpeg', sha256: await sha256Hex(bytes), base64: toBase64(bytes) };
  } finally {
    stream.getTracks().forEach((t) => t.stop());
    if (dialog.open) dialog.close();
  }
}

export async function sha256Hex(bytes) {
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

export function toBase64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(s);
}
