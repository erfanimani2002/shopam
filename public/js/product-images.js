/* Product image manager: client-side downscale (canvas, max 1600px long
 * side, JPEG quality 0.85, EXIF orientation handled via createImageBitmap
 * with imageOrientation:'from-image'), then upload with progress. Cover,
 * delete and reorder operate through the API. */
'use strict';

var ProductImages = (function () {
  var productId = 0;
  var images = [];
  function $(id) { return document.getElementById(id); }

  function mount(id) {
    productId = id;
    var box = $('dlg-images');
    if (!id) {
      box.innerHTML = '<div class="muted small">برای افزودن تصویر، ابتدا محصول را ذخیره کنید.</div>';
      return;
    }
    box.innerHTML = ''
      + '<div class="img-head"><strong>تصاویر محصول</strong>'
      + '<input type="file" id="img-input" accept="image/jpeg,image/png,image/webp" multiple>'
      + '</div>'
      + '<div id="img-progress" class="progress hidden"><div id="img-bar"></div></div>'
      + '<div id="img-list" class="img-list"></div>';
    $('img-input').addEventListener('change', onPick);
    refresh();
  }

  function refresh() {
    if (!productId) return;
    APP.get('product_get', { id: productId }).then(function (j) {
      if (!j.ok) { APP.toast((j.error && j.error.message) || 'خطا', false); return; }
      images = j.data.product.images || [];
      render();
    });
  }

  function render() {
    var box = $('img-list');
    if (!images.length) {
      box.innerHTML = '<div class="muted small">تصویری ثبت نشده است.</div>';
      return;
    }
    box.innerHTML = images.map(function (im, index) {
      var controls = ''
        + (index > 0 ? '<button class="mini up" data-id="' + im.id + '">▲</button>' : '')
        + (index < images.length - 1 ? '<button class="mini down" data-id="' + im.id + '">▼</button>' : '')
        + (index !== 0 ? '<button class="mini cover" data-id="' + im.id + '">کاور</button>' : '<span class="badge coverbadge">کاور</span>')
        + '<button class="mini del" data-id="' + im.id + '">حذف</button>';
      return '<div class="img-row" data-id="' + im.id + '">'
        + '<img loading="lazy" src="' + APP.esc(APP.imageUrl(im)) + '" alt="">'
        + '<span class="muted small">' + Format.toPersianDigits(index + 1) + '</span>'
        + '<span class="spacer"></span>' + controls + '</div>';
    }).join('');

    box.querySelectorAll('.up, .down, .cover, .del').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = +b.getAttribute('data-id');
        if (b.classList.contains('del')) return remove(id);
        var order = images.map(function (im) { return im.id; });
        var i = order.indexOf(id);
        if (b.classList.contains('up') && i > 0) {
          order.splice(i, 1); order.splice(i - 1, 0, id);
        } else if (b.classList.contains('down') && i < order.length - 1) {
          order.splice(i, 1); order.splice(i + 1, 0, id);
        } else if (b.classList.contains('cover')) {
          order.splice(i, 1); order.unshift(id);
        }
        APP.post('product_image_reorder', { product_id: productId, ids: order }).then(function (j) {
          if (j.ok) { images = j.data.images; render(); }
          else APP.toast((j.error && j.error.message) || 'خطا', false);
        });
      });
    });
  }

  function remove(id) {
    APP.post('product_image_delete', { product_id: productId, id: id }).then(function (j) {
      if (j.ok) { images = j.data.images; render(); APP.toast('تصویر حذف شد', true); }
      else APP.toast((j.error && j.error.message) || 'خطا', false);
    });
  }

  /* --- client-side downscale before upload (change #1) --- */
  function downscale(file) {
    var draw = function (source, w, h) {
      var max = 1600;
      var scale = Math.min(1, max / Math.max(w, h));
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(w * scale));
      canvas.height = Math.max(1, Math.round(h * scale));
      canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', 0.85);
      });
    };
    // createImageBitmap applies EXIF orientation with 'from-image'.
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
        return draw(bmp, bmp.width, bmp.height).then(function (blob) {
          bmp.close && bmp.close();
          return blob;
        });
      }).catch(function () { return legacy(file); });
    }
    return legacy(file);
  }

  /** Fallback for browsers without createImageBitmap options: <img> decode. */
  function legacy(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        draw(img, img.naturalWidth, img.naturalHeight).then(function (blob) {
          URL.revokeObjectURL(url);
          resolve(blob);
        });
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
      img.src = url;
    }).then(function (r) { return r === undefined ? null : r; });
  }

  function onPick() {
    var input = $('img-input');
    var files = Array.prototype.slice.call(input.files || []);
    if (!files.length) return;
    files = files.slice(0, 5); // server accepts at most 5 per request
    var progress = $('img-progress');
    var bar = $('img-bar');
    progress.classList.remove('hidden');
    bar.style.width = '0%';

    Promise.all(files.map(downscale)).then(function (blobs) {
      var fd = new FormData();
      fd.append('csrf_token', APP.csrfToken);
      fd.append('product_id', String(productId));
      var names = [];
      blobs.forEach(function (blob, i) {
        if (!blob) return; // unreadable file — skip
        fd.append('files', blob, 'p' + i + '.jpg');
        names.push(files[i].name);
      });
      if (!names.length) {
        progress.classList.add('hidden');
        APP.toast('هیچ فایل قابل پردازشی انتخاب نشد', false);
        return;
      }
      APP.upload('product_image_upload', fd, function (ratio) {
        bar.style.width = Math.round(ratio * 100) + '%';
      }).then(function (j) {
        progress.classList.add('hidden');
        input.value = '';
        if (!j.ok) { APP.toast((j.error && j.error.message) || 'خطا در بارگذاری', false); return; }
        images = j.data.images;
        render();
        (j.data.failed || []).forEach(function (f) {
          APP.toast((f.name ? f.name + ': ' : '') + (f.message || 'فایل رد شد'), false);
        });
        if (j.data.inserted > 0) APP.toast(Format.toPersianDigits(j.data.inserted) + ' تصویر اضافه شد', true);
      });
    });
  }

  return { mount: mount };
})();
