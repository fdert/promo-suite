# أداة النشر التلقائي — Hostinger VPS

> ✅ **هذا المستودع مُجمّع بالكامل مسبقًا** (backend/ + frontend/ + أداة النشر في مكانها الصحيح). كل ما تحتاجه هو تحويله لمستودع Git ورفعه:
> ```bash
> cd promo-suite   # المجلد الذي فككت هذا الملف المضغوط بداخله
> git init
> git add .
> git commit -m "Initial commit: promo suite (backend + frontend + deploy tool)"
> git branch -M main
> git remote add origin <رابط مستودعك على GitHub>
> git push -u origin main
> ```
> ثم أكمل من قسم "المتطلبات قبل البدء" أدناه مباشرة.

نشر تلقائي عند كل push إلى main، مع الاحتفاظ بالنسخ السابقة والقدرة على التراجع الفوري (rollback) بدون إعادة بناء.

## الفكرة (نمط releases/current المعروف في DevOps)

```
~/deployments/
  releases/
    20260719120000/     ← نسخة قديمة (محتفظ بها)
    20260719130500/     ← النسخة الحالية الحيّة
  shared/                ← بيانات دائمة لا تُلمس أبدًا عند أي نشر
    api/config.php         (بيانات قاعدة البيانات — لا تُرفع لـ git أبدًا)
    uploads/                (ملفات العملاء، وسائط واتساب)
    logs/                   (سجل أخطاء PHP)
  current -> releases/20260719130500   ← رابط رمزي واحد يشير دائمًا للنسخة الحيّة
  scripts/               ← نسخة من scripts/ (activate_release.sh, rollback.sh)
```

خادم الويب (Apache) يشير دائمًا إلى `current` وليس لمجلد نسخة محددة. كل نشر جديد:
1. يبني الواجهة الأمامية (`npm run build`) **على خادم GitHub Actions نفسه**، وليس على الـ VPS (أخف على موارد الاستضافة).
2. يرفع النسخة الجديدة كاملة إلى `releases/<توقيت>/` عبر rsync.
3. يربط الملفات الدائمة (`config.php`, `uploads/`, `logs/`) من `shared/` داخل النسخة الجديدة.
4. **يتحقق من صحة أكواد PHP (`php -l`) قبل أي تبديل** — إن فشل، تبقى النسخة القديمة تعمل بدون أي تأثير.
5. يبدّل رابط `current` بشكل ذرّي (لا توجد لحظة "نصف منشور").
6. يحذف النسخ الأقدم من آخر 5 (قابل للتعديل)، دون المساس بالنسخة الحيّة الحالية.

**التراجع (Rollback)** فوري: `bash scripts/rollback.sh --previous` على الخادم — إعادة توجيه رابط رمزي فقط، لا إعادة بناء ولا وقت انتظار.

---

## المتطلبات قبل البدء

1. **VPS من Hostinger مع وصول SSH كامل (root أو مستخدم بصلاحيات sudo)**.
2. **مستودع Git واحد يجمع المشروعين** بهذا الترتيب بالضبط (هذه الأداة تفترض هذا الشكل):
   ```
   your-repo/
     backend/        ← محتوى حزمة promo-sync-suite-backend (api/, .htaccess, uploads/, ...)
     frontend/       ← محتوى حزمة promo-suite-frontend (package.json, src/, ...)
     scripts/        ← هذه الأداة (activate_release.sh, rollback.sh, server_bootstrap.sh)
     .github/workflows/deploy.yml   ← هذه الأداة
     apache-vhost.conf.example       ← هذه الأداة
   ```
   ✅ هذا المستودع منظّم بهذا الشكل بالفعل — لا حاجة لنسخ أي شيء يدويًا.
3. PHP و Apache مثبّتان على الـ VPS (متوفران افتراضيًا على معظم صور Hostinger VPS). Node.js **غير مطلوب على الـ VPS نفسه** — البناء يتم على GitHub فقط.

---

## الإعداد لأول مرة

### 1) على الـ VPS (مرة واحدة فقط)
```bash
ssh youruser@your-vps-ip
DEPLOY_PATH=/home/youruser/deployments bash -s < server_bootstrap.sh
```
(انسخ محتوى `scripts/server_bootstrap.sh` من هذه الحزمة، أو ادفع المستودع أولاً وشغّله من هناك.)

ثم **عدّل بيانات قاعدة البيانات الحقيقية**:
```bash
nano /home/youruser/deployments/shared/api/config.php
```

### 2) أنشئ مفتاح SSH مخصص للنشر (لا تستخدم مفتاحك الشخصي)
```bash
ssh-keygen -t ed25519 -f deploy_key -N ""
```
أضف `deploy_key.pub` إلى `~/.ssh/authorized_keys` على الـ VPS، واحتفظ بـ `deploy_key` (الخاص) لإضافته في GitHub.

### 3) أضف الأسرار (Secrets) في GitHub
في مستودعك: Settings → Secrets and variables → Actions → New repository secret:

| الاسم | القيمة |
|---|---|
| `VPS_HOST` | عنوان IP أو نطاق الخادم |
| `VPS_USER` | اسم مستخدم SSH (مثال: `youruser`) |
| `VPS_SSH_KEY` | محتوى ملف `deploy_key` الخاص كاملاً |
| `VPS_DEPLOY_PATH` | `/home/youruser/deployments` |
| `VPS_SSH_PORT` | (اختياري) رقم المنفذ إن لم يكن 22 |

### 4) اضبط Apache
انسخ `apache-vhost.conf.example`، عدّل النطاق والمسارات، فعّله، وأعد تشغيل Apache. تأكد أن `DocumentRoot` يشير إلى `.../deployments/current` (الرابط الرمزي، وليس مجلد نسخة محدد).

### 5) ادفع إلى main
```bash
git push origin main
```
راقب التقدم من تبويب Actions في GitHub. عند النجاح، الموقع يعمل بالنسخة الجديدة فورًا.

### 6) بعد أول نشر ناجح — خطوات لمرة واحدة
```bash
ssh youruser@your-vps
cd deployments/current/api
php migrate_tenant.php
php create_platform_admin.php you@email.com "StrongPassword123" "اسمك"
```

---

## الاستخدام اليومي

- **نشر تحديث جديد**: فقط `git push origin main` — كل شيء تلقائي.
- **إعادة نشر يدوي بدون كوميت جديد**: تبويب Actions في GitHub → اختر workflow "Deploy to Hostinger VPS" → Run workflow.
- **التراجع لنسخة سابقة**:
  ```bash
  ssh youruser@your-vps
  cd deployments && bash scripts/rollback.sh --previous
  ```
- **عرض كل النسخ المتوفرة للتراجع إليها**:
  ```bash
  bash scripts/rollback.sh
  ```

## ⚠️ ملاحظات مهمة
- الرابط الرمزي `current` هو ما يحدد النسخة الحيّة — لا تعدّل أي ملف داخل `releases/` مباشرة، لأنه سيُحذف عند أول تنظيف نسخ قديمة.
- الترقيات في قاعدة البيانات (مثل `migrate_tenant.php`) **لا تتراجع تلقائيًا** عند استخدام `rollback.sh` — التراجع يخص كود التطبيق فقط.
- لم أستطع اختبار هذا التدفق كاملاً (GitHub Actions ↔ VPS حقيقي) في هذه الجلسة لعدم توفر اتصال إنترنت لبيئة العمل هنا. اختبره أولًا بدفعة (push) تجريبية بسيطة، وراقب سجل الـ Actions بعناية.
