/**
 * Shrink a photo in the browser before it goes up.
 *
 * A phone camera file is routinely 4–12 MB, which the server rejects outright
 * past 5 MB and which, on a mobile connection, can outlast the gateway long
 * before it reaches storage. Re-encoding to something screen-sized turns that
 * into a few hundred KB with no visible loss at the sizes a catalog shows.
 *
 * Every failure path returns the original file: compression is an optimisation,
 * never a reason an upload cannot happen.
 */

export type CompressOptions = {
    /** Longest edge of the result, in pixels. */
    maxDimension?: number;
    /** Encoder quality, 0–1. */
    quality?: number;
    /** Files at or below this stay untouched. */
    skipBelowBytes?: number;
};

const DEFAULTS = {
    maxDimension: 2000,
    quality: 0.82,
    skipBelowBytes: 256 * 1024,
} satisfies Required<CompressOptions>;

/**
 * Formats we re-encode. GIF is excluded because a canvas keeps only the first
 * frame, which would silently drop the animation; AVIF because browser encode
 * support is still thin enough that the round trip often lands larger.
 */
const RECOMPRESSIBLE = ['image/jpeg', 'image/png', 'image/webp'];

let webpSupport: boolean | null = null;

/** Whether this browser can *encode* WebP, which is the alpha-safe target. */
const canEncodeWebp = (): boolean => {
    if (webpSupport === null) {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            webpSupport = canvas
                .toDataURL('image/webp')
                .startsWith('data:image/webp');
        } catch {
            webpSupport = false;
        }
    }

    return webpSupport;
};

const toBlob = (
    canvas: HTMLCanvasElement,
    type: string,
    quality: number,
): Promise<Blob | null> =>
    new Promise((resolve) => canvas.toBlob(resolve, type, quality));

export const compressImage = async (
    file: File,
    options: CompressOptions = {},
): Promise<File> => {
    const { maxDimension, quality, skipBelowBytes } = {
        ...DEFAULTS,
        ...options,
    };

    if (!RECOMPRESSIBLE.includes(file.type) || file.size <= skipBelowBytes) {
        return file;
    }

    let bitmap: ImageBitmap;

    try {
        // `from-image` applies the EXIF rotation, so a photo taken sideways
        // does not land sideways in the catalog.
        bitmap = await createImageBitmap(file, {
            imageOrientation: 'from-image',
        });
    } catch {
        return file;
    }

    try {
        const scale = Math.min(
            1,
            maxDimension / Math.max(bitmap.width, bitmap.height),
        );

        // Already small enough and already well compressed — re-encoding would
        // only cost quality.
        if (scale === 1 && file.size <= skipBelowBytes * 4) {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);

        const context = canvas.getContext('2d');

        if (!context) {
            return file;
        }

        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        // WebP keeps transparency; JPEG would flatten a cut-out product shot
        // onto black, so it is only safe once we know the source is opaque.
        const type = canEncodeWebp()
            ? 'image/webp'
            : file.type === 'image/png'
              ? 'image/png'
              : 'image/jpeg';

        const blob = await toBlob(canvas, type, quality);

        // PNG ignores quality, so a screenshot can come back bigger than it
        // went in. Keep whichever is actually smaller.
        if (!blob || blob.size >= file.size) {
            return file;
        }

        const extension = type.split('/')[1];
        const name = file.name.replace(/\.[^./\\]+$/, '') + '.' + extension;

        return new File([blob], name, { type, lastModified: Date.now() });
    } catch {
        return file;
    } finally {
        bitmap.close();
    }
};
