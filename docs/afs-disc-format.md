# Acorn AFS ("AFS0") on-disc format — Level 3 File Server and FileStore

Written 2026-10-12. Each statement is tagged with how it was established:

- **[SRC]** read from the Acorn 6502 source (stardot `Acorn-Fileservers`, combined
  Level 3 / FileStore v1.33 tree, `FileServer/SRC/FileServer/`). File names such as
  `Uade11` refer to files there. This is the authority.
- **[IMG]** verified against a dump of a hardisk to an image file  (see §11).
- **[DOC]** from secondary documentation only (heyrick.eu, oaknut-afs docstrings);
  not independently verified.

If `src/L3fsReader.php` disagrees with this document, this document is right;
the faults originally found in it are listed in §10.

---

## 1. Basics

- Sector ("block") size is always **256 bytes** (`BLKSZE`). [SRC]
- All multi-byte integers are **little-endian**. [SRC]
- Sectors are numbered linearly from 0 at the start of the disc. A hard disc image
  is a flat dump: byte offset = sector × 256. [IMG]
- A "cylinder" is just a group of `spc` (sectors-per-cylinder) consecutive sectors;
  cylinder `c` occupies sectors `c*spc .. c*spc+spc-1`. Heads and tracks are not
  otherwise visible to the filing system.
- **SIN** (System Internal Name) = the 24-bit absolute sector number of an
  object's **map sector**. Directory entries and the info sector refer to objects
  only by SIN. A SIN is never the address of the data itself.
- Floppy images (`.adl`) may be side-interleaved; hard disc images are not.

The disc has two regions:

| Region | Cylinders | Contents |
|---|---|---|
| ADFS stub | `0 .. start_cyl-1` (normally just cylinder 0) | A tiny fake ADFS filesystem so ADFS sees a full disc; holds the pointers to the AFS info sectors |
| AFS region | `start_cyl .. ncyl-1` | Bitmaps, two info sectors, map sectors, directories, file data |

---

## 2. ADFS stub: sectors 0 and 1 (the only real checksums on the disc)

Sectors 0 and 1 are an ADFS "old map" free-space map, with one extra field.

Sector 0:

| Offset | Size | Meaning |
|---|---|---|
| 0x00.. | 3 each | ADFS free-space start addresses (sectors) |
| **0xF6** | 3 | **Sector number of AFS info sector, copy 1** (`SZOFF`) |
| 0xFC | 3 | ADFS disc size in sectors (set to one cylinder) |
| 0xFF | 1 | ADFS checksum of sector 0 |

Sector 1:

| Offset | Size | Meaning |
|---|---|---|
| 0x00.. | 3 each | ADFS free-space lengths |
| **0xF6** | 3 | **Sector number of AFS info sector, copy 2** |
| 0xFB | 2 | ADFS disc ID |
| 0xFD | 1 | Boot option |
| 0xFE | 1 | End-of-free-list pointer (3 × number of entries) |
| 0xFF | 1 | ADFS checksum of sector 1 |

**The two 0xF6 pointers are different by design**: sector 0 points at copy 1,
sector 1 points at copy 2. [SRC `Form00`, `Uade12` RDDATA] [IMG: 245 and 489]

Only the low 21 bits of the pointer are an address; the server ORs the drive
number into the top 3 bits of the third byte when building the SCSI command.
[SRC `Uade12`]

How the server mounts [SRC `Uade12` RDDATA]: read sector 0, follow 0xF6, read
that sector. If either read gives a disc error, retry once using sector 1 and its
0xF6. Then `CHKDSC` checks only that bytes 0–3 are `"AFS0"`. The two copies are
never compared with each other, and **the checksum is never verified on read**.

### 2.1 Checksum algorithm (standard ADFS old-map checksum)

```
sum = 255
for i from 254 down to 0:
    sum = (sum & 0xFF) + sector[i] + (sum >> 8)     # add with carry from previous add
checksum = sum & 0xFF                               # stored at sector[255]
```

[SRC `Mbbmcm` WRBLK: stores 0xFF at offset 0xFF, then `ADC` from 0xFF down to 0]
[IMG: sector 0 stored 0xF1 = computed; sector 1 stored 0x37 = computed]

Notes:
- Level 3 recomputes this whenever it writes logical sector 0. In the FileStore
  build that code is disabled (`[ 1=0 ;**23/1/87** never use sector zero this
  way`); FileStore `FORMAT` writes precomputed constants instead.
- Sector 1's checksum (offset 0x1FF in the image) uses the same algorithm.
- **Nothing inside the AFS region is checksummed.** No CRC, no sum — on info
  sectors, bitmaps, map sectors, directories or data. Integrity there rests only
  on magic strings and paired sequence numbers (§4, §5), plus the structural
  cross-checks in §8.

Sectors 2–6 hold a dummy ADFS root directory (`"Hugo"` at offset 1 of sector 2).
Useful only as a fingerprint.

---

## 3. AFS info sector (two identical copies)

Located via the 0xF6 pointers. By construction copy 1 is at
`start_cyl*spc + 1` and copy 2 is at `copy1 + spc` — i.e. sector 1 of the first
two AFS cylinders (hard disc). [IMG: 245, 489 with spc=244] On a FileStore floppy
they are the last two sectors of cylinder 0 (`spc-2`, `spc-1`). [SRC `Form00`]

| Offset | Size | Label | Meaning |
|---|---|---|---|
| 0 | 4 | `MPDRNM` | Magic `"AFS0"` |
| 4 | 16 | `MPSZNM` | Disc name, space padded |
| 20 | 2 | `MPSZNC` | Number of cylinders |
| 22 | 3 | `MPSZNS` | Total sectors on disc |
| 25 | 1 | `MPSZDN` | Number of physical discs (1) |
| 26 | 2 | `MPSZSC` | Sectors per cylinder (`spc`) |
| 28 | 1 | `MPSZSB` | Size of bitmap in sectors (1 on hard disc; 2 on floppy) |
| 29 | 1 | `MPSZAF` | Addition factor to reach next physical drive (1) |
| 30 | 1 | `MPSZDI` | Increment to next logical drive (1) |
| 31 | 3 | `MPSZSI` | **SIN of root directory** |
| 34 | 2 | `MPSZDT` | Date of initialisation (§7) |
| 36 | 2 | `MPSZSS` | Start cylinder of AFS region (first cylinder with a bitmap) |
| 38 | 1 | `MPSZ1B` | 0 = one bitmap sector per cylinder (hard disc). Non-zero = packed bitmaps, value is bytes of bitmap per cylinder (floppy) |
| 39–255 | | | Zero |

[SRC `Uade02`; bytes 20–38 are block-copied into the in-memory map table by
`SMAPEN`] [IMG: all fields consistent]

Sanity checks a recovery tool should apply (none are done by the server):
`ncyl * spc == total_sectors`; `total_sectors * 256 == image size` (single
drive); `start_cyl < ncyl`; `root SIN < total_sectors` and not a bitmap sector;
bytes 39–255 zero; the two copies byte-identical.

If both pointers or both copies are lost, the geometry can be recovered by
scanning for `"AFS0"` at offset 0 of any sector (a hit at sector `s` implies
`spc` divides the distance between the two hits), or inferred from bitmaps (§4).

---

## 4. Free-space bitmaps

### 4.1 Hard disc (byte 38 of info sector = 0)

**Sector 0 of every cylinder from `start_cyl` to `ncyl-1` is that cylinder's
bitmap.** [SRC `Uade11` RDBTMP] [IMG]

- Bit for sector `n` of the cylinder is bit `n & 7` of byte `n >> 3`.
- **1 = free, 0 = allocated.**
- Bit 0 of byte 0 is always 0 (the bitmap sector itself).
- Bits at index ≥ `spc`, and all following bytes, are 0. [IMG: all 305 bitmaps]
- **Byte 0xFF (`MPVAL`)**: if non-zero `n`, the server skips reading the next `n`
  cylinders' bitmaps at start-up and treats them as having no free space. It is
  an optimisation flag, not a checksum. Not used with packed bitmaps.
  [SRC `Uade13`] [IMG: always 0]

A bitmap sector has **no magic and no sequence number**. It is identified purely
by position (`sector % spc == 0`, cylinder ≥ start) and plausibility (bit 0
clear, nothing set at or beyond bit `spc`).

Consequences of the layout that are useful as validity tests [IMG: held for all
1891 live maps]:
- No extent starts on, or runs across, a sector with `sector % spc == 0`.
  Therefore **no extent crosses a cylinder boundary**.
- No map sector lies on a bitmap sector.

### 4.2 Floppy / packed (byte 38 = `B` ≠ 0) [SRC `Uade11` RDBTMP, `Form00`]

All cylinders' bitmaps are packed into `MPSZSB` consecutive sectors starting at
sector `start_cyl * spc`. Entries are `B = ceil(spc/8)` bytes each, indexed by
**absolute** cylinder number from 0, and an entry never straddles a sector:

```
per_sector = 256 // B
sector     = start_cyl*spc + (cyl // per_sector)
offset     = (cyl % per_sector) * B
```

Standard FileStore floppy: 80 cylinders, spc = 32, B = 4, 2 bitmap sectors at
32–33, info sectors at 30 and 31, root map at 34, root data 35–36, Passwords map
37, data 38.

### 4.3 Bitmaps in recovery

Bitmaps are redundant: they can be rebuilt from the set of reachable map sectors
and extents. Use them as a cross-check only — "allocated in bitmap but not
reachable from the tree" marks sectors that belonged to objects whose directory
entry was lost. Never trust a bitmap over a map sector.

---

## 5. Map sector ("JesMap") — one or more per object

The SIN of every file and directory is the address of its head map sector.

| Offset | Size | Label | Meaning |
|---|---|---|---|
| 0 | 6 | | Magic `"JesMap"` (head block only — see §5.2) |
| 6 | 1 | `MBSQNO` | Leading sequence number |
| 7 | 1 | `MGFLG` | Flags, always 0 |
| 8 | 2 | `BILB` | Bytes in last block (0 = last data sector is full). Only values 0–255 occur |
| 10 | 5 × 48 | `MBENTS` | Extent slots 0–47: 3-byte start sector, 2-byte length in sectors |
| 250 | 5 | `LSTENT` | Slot 48: chain link to next map block, or zero |
| 255 | 1 | `LSTSQ` | Trailing sequence number |

[SRC `Uade02`, `Uade10` MPCRSP, `Uade13` MPGTSZ, `Uade11` RDMPBK]

### 5.1 Reading rules

- Walk slots from 0. **The list ends at the first slot whose 3-byte start is
  zero** (test the start, not the length). [SRC MPGTSZ]
- Reaching slot 48 with a non-zero start means "continue in the map block at that
  sector" (length field is 1). Slot 48 is never a data extent, so a block holds
  at most 48 extents.
- Extent starts are absolute sector numbers.
- Object size: let `T` = total sectors over all extents in the chain.
  `T == 0` → 0 bytes; `BILB == 0` → `T*256`; else `(T-1)*256 + BILB`.
- **Which block's BILB?** The v1.33 source re-reads the **head** block (the SIN)
  for BILB after walking the chain (`MPGTSZ`, change dated 8/5/87), and
  `MPCRSP` writes it to the head. oaknut-afs documents it as living in the final
  block. For single-block maps (all 1891 in the test image) there is no
  difference. For chained maps: take the head's value, and flag the object if the
  last block's value differs.

### 5.2 Validity — the map sector check

The server's **only** test when loading a map block [SRC RDMPBK]:

```
valid  <=>  sector[6] == sector[255]        (else error "SIN not for start of chain")
```

It does not check the magic. The magic is written only when an object is first
created, so:

- **Head blocks** carry `"JesMap"` at 0–5.
- **Successor blocks** (reached via slot 48) reportedly have **zeros** at 0–5,
  because the routine that allocates them clears the buffer and never writes the
  magic. [DOC oaknut-afs citing `Uade12` MKRLN; no chained map exists in the
  test image, so unverified here.] A successor is therefore only recognisable by
  being pointed at, plus matching sequence bytes.

Sequence number behaviour:
- Both bytes are incremented together on every write of the block (`MMBWT`).
  They are a torn-write/"is this a map" marker, not a version to compare between
  blocks. Blocks in a chain have **independent** sequence numbers.
- **Deletion signature**: on delete the server sets byte 6 = 0xFF and byte 255 =
  0x00, then the normal write increments both → **byte 6 = 0x00, byte 255 =
  0x01**, with the magic left intact and the extent list cleared.
  [SRC `Uade12A` DAMPBK] [IMG: 37 sectors, all `JesMap`, seq (0,1), zero extents]
  So a deleted object's map is recognisable but cannot be used to undelete.

Recommended classifier for "is sector `s` a live head map?" — all must hold:

1. bytes 0–5 == `"JesMap"`
2. byte 6 == byte 255
3. byte 7 == 0 and byte 9 == 0
4. `s % spc != 0` and `s` ≥ start of AFS region
5. every extent before the terminator: `start ≥ start_cyl*spc`, `length ≥ 1`,
   `start + length ≤ total_sectors`, does not touch a bitmap sector, does not
   cross a cylinder
6. all bytes between the terminator and offset 254 are zero
7. extents do not overlap each other, the map sector itself, or (after a global
   pass) any other object's sectors

Rules 3 and 6 matter: file data can legitimately contain the text `JesMap` at a
sector start (one such sector exists in the test image and fails them).

Soft hints, true for every live map in the test image but **not** guaranteed by
the format: first extent starts at `SIN + 1`; first extent is in the same
cylinder as the map; 97% of objects have exactly one extent.

---

## 6. Directories

A directory is an ordinary object: SIN → map → extents → byte stream. Its size
comes from its map exactly like a file's.

### 6.1 Header (17 bytes)

| Offset | Size | Label | Meaning |
|---|---|---|---|
| 0 | 2 | `DRFRST` | Offset of first in-use entry (0 = none) |
| 2 | 1 | `DRSQNO` | Leading sequence number |
| 3 | 10 | `DRNAME` | Directory's own name, space padded (`"$         "` for root) |
| 13 | 2 | `DRFREE` | Offset of first free entry (0 = none) |
| 15 | 2 | `DRENTS` | Number of in-use entries |

### 6.2 Entry slots (26 bytes each, starting at offset 17)

| Offset | Size | Label | Meaning |
|---|---|---|---|
| 0 | 2 | `DRLINK` | Offset of next entry in the same list (0 = end) |
| 2 | 10 | `DRTITL` | Name, space padded [IMG: 1708 of 1723 space, rest full length] |
| 12 | 4 | `DRLOAD` | Load address |
| 16 | 4 | `DREXEC` | Execute address |
| 20 | 1 | `DRACCS` | Type and access byte (below) |
| 21 | 2 | `DRDATE` | Date (§7) |
| 23 | 3 | `DRSIN` | SIN of the object |

- All offsets are from the start of the directory byte stream, and always equal
  `17 + 26*k`. Slots are fixed; entries freely straddle sector boundaries.
- Two singly linked lists thread the slots: in-use (head `DRFRST`), kept in
  **alphabetical order, case-insensitive**; and free (head `DRFREE`). Free slots
  keep stale name/SIN bytes from deleted entries — only their link is meaningful.
- Maximum 255 entries.

Access byte [SRC `Uade01`] [IMG]:

| Bit | Value | Meaning |
|---|---|---|
| 0 | 0x01 | Public read |
| 1 | 0x02 | Public write |
| 2 | 0x04 | Owner read |
| 3 | 0x08 | Owner write |
| 4 | 0x10 | Locked |
| 5 | 0x20 | **Directory** (0 = file) |
| 6–7 | | Not stored on disc (used in memory) |

Typical values seen: 0x15 `LR/R`, 0x0D `WR/R`, 0x0C `WR/`, 0x14 `LR/`, 0x30
directory (created as `TYPDIR+LOCKED`), 0x20 directory.

### 6.3 Parent pointer pseudo-entry [SRC `Uade0E` MAKDIR, `Uade0DA` RETPAR]

Since the change dated 23/1/87 (all FileStores; later Level 3), the **first slot
(offset 17) is a dummy, unchained entry**: link = `0xFFFF`, everything else zero
except `DRSIN` (offset 17+23 = 40), which holds the **SIN of the parent
directory**. It is on neither list and not counted in `DRENTS`.

[IMG: 119 of 120 directories have it and the parent SIN resolves to a real
directory.] Directories created by older Level 3 versions, and the root, may not
have it: test for link == 0xFFFF before using it.

This is the single most valuable field for recovery: **the tree can be rebuilt
bottom-up** from any directory whose first sector survives, even when the parent
directory is lost.

### 6.4 Size and the trailing sequence number

Directory size in bytes is always exactly

```
size = 17 + 26*N + 1          # N slots, including the parent pseudo-slot
```

and the **last byte (offset `size-1`) is a copy of the leading sequence number**
at offset 2. [SRC `Uade0E` FORMAT, `Uade0D` LOADDR] It is at `size-1`, which is
generally *not* the last byte of the last sector: the map's BILB equals
`size % 256`. [IMG: 120/120] A new directory is 2 sectors → 512 bytes → 19 slots
(17 + 19×26 + 1 = 512 exactly).

The server's only directory validity test on load:

```
valid  <=>  dir[2] == dir[size-1]           (else "broken directory")
```

Both bytes are incremented together each time the directory is written. The
point is to detect a multi-sector directory that was only partly written.

### 6.5 Recognising a directory without its parent

A head map `s` is a directory if its data satisfies all of:

- `(size - 18) % 26 == 0` and `size ≥ 44`
- `dir[2] == dir[size-1]`
- name at 3–12 is printable ASCII, non-empty, space padded
- `DRFRST` and `DRFREE` are each 0 or of the form `17 + 26k` and `< size`
- `DRENTS ≤ 255` and equals the length of the in-use list
- in-use + free (+1 if parent pseudo-slot present) == `(size-18)/26` [IMG:
  115/120 exact; a few directories have leaked slots, so treat as a warning]
- in-use names are in ascending case-insensitive order

Cross-checks between directories (strong evidence when both sides survive):

- child's parent pseudo-entry SIN == SIN of the directory holding the entry
- child's header name (`DRNAME`) == the entry's name in the parent
- entry's access bit 5 set ⇔ target is a directory by the test above
- entry's SIN is a live head map (§5.2)

---

## 7. Dates

Two bytes [DOC; consistent with IMG]:

- byte 0: bits 0–4 = day of month; bits 5–7 = high bits of (year − 1981)
  (FileStore extension; zero on Level 3)
- byte 1: bits 0–3 = month; bits 4–7 = low 4 bits of (year − 1981)

`year = 1981 + (byte1 >> 4) + ((byte0 >> 5) << 4)`. Example: `0F 53` = 15 March
1986.

---

## 8. Password file

`$.Passwords`, an ordinary file that must be a whole number of sectors (BILB =
0). Entries are 31 bytes [SRC `Uade02`, `Form00`]:

| Offset | Size | Meaning |
|---|---|---|
| 0 | 20 | User id, CR (0x0D) terminated if shorter; may be `group.user` |
| 20 | 6 | Password, CR terminated if shorter |
| 26 | 4 | Free space quota, little-endian |
| 30 | 1 | Flags: bit 7 in use, bit 6 system privilege, bits 0–1 boot option |

Older Level 3 releases without quotas are believed to use shorter entries (no
free-space field) — unverified; detect by checking which entry size makes the
flag bytes line up.

[IMG] `fsdisc.img` agrees with the 31-byte layout as far as it can be checked:
the first sector of its `$.Passwords` is lost, but in the second sector the
entry at offset 248 ends with flag 0x80 at 278, and the entry at 279 is user
`Basic` with a CR at the start of the password field (no password), zero free
space and flag 0x80 at 309. Bytes after the CR in a field are stale and must be
ignored (that password field reads `0D 63 6C 69 62 2E`).

---

## 9. Recovery design notes

### 9.1 Know which sectors are bad

The format cannot tell a lost sector from a legitimately zero one. In the test
image missing sectors appear to be zero-filled, but 1043 allocated sectors are
all-zero and most are probably real data. The recovery class should accept an
explicit bad-sector list (e.g. a ddrescue map file) and fall back to
"all-zero ⇒ suspect" only when none is available, reporting suspects rather than
asserting loss.

### 9.2 What each kind of loss costs

| Lost sector | Effect | Recovery |
|---|---|---|
| Sector 0 or 1 | One pointer to an info sector | Use the other; or scan for `AFS0` |
| One info sector | Nothing | Use the other copy |
| Both info sectors | Geometry and root SIN | Infer `spc` from bitmap spacing / known drive geometry; find root as the directory named `$`, or as the common ancestor of parent pointers |
| A bitmap | Nothing needed for reading | Rebuild from maps |
| Head map of a file | The file's location | Unrecoverable by structure; data sectors show up as "allocated but unowned" (usually `SIN+1` onwards, contiguous) and can be dumped as an orphan blob with length unknown |
| Head map of a directory | Location of directory data | Directory data usually sits at `SIN+1`; children still name this SIN as parent, so contents can be rebuilt from children (names from each child's own header; files' names lost) |
| Successor map block | Tail of a large fragmented file | Dump what the earlier blocks cover, mark truncated |
| First sector of a directory | Header, slots 0–9 (slot 9 straddles), leading seq | See 9.3 |
| Later sector of a directory | The slots in it, and the list links through them | See 9.3 |
| Data sector of a file | 256 bytes of content | Dump with a hole (zero or pattern fill) and record the byte range in the report |

### 9.3 Salvaging a damaged directory

Slots are positional, so parse every slot `k` whose 26 bytes lie wholly in
surviving sectors (offset `17 + 26k`), ignoring the linked lists:

1. Treat a slot as a candidate in-use entry if its name is printable and space
   padded, its access byte uses only bits 0–5, and its SIN is a live head map
   whose type matches access bit 5.
2. Free slots hold stale data, so confirm candidates: follow the surviving link
   chains; a chain in ascending name order whose members all pass step 1 is the
   in-use list. Slots that only appear on the other chain are free. A candidate
   whose SIN is also claimed by another directory, or whose target directory
   names a different parent, is stale.
3. Add children found from the other direction: every directory whose parent
   pseudo-entry names this SIN belongs here, under the name in its own header.
4. The trailing sequence byte at `size-1` survives if the last sector does; it
   equals the lost leading byte.

### 9.4 Suggested overall algorithm

1. Read geometry (info sector, with fallbacks). Load bad-sector list.
2. **Scan every sector** and classify: info, bitmap position, live head map,
   deleted map (seq 0/1), other.
3. For each live head map, resolve its chain and extents; record sector
   ownership; flag overlaps and extents touching bad sectors.
4. Classify each object as directory or file (§6.5).
5. Build the tree top-down from the root where directories are intact, and
   bottom-up from parent pointers where they are not. Attach anything still
   unowned under a synthetic `lost+found`, named by SIN.
6. Dump files with a manifest: path, SIN, load/exec, access, date, size, extent
   list, and a per-file status (complete / holes at byte ranges / truncated /
   orphan / name unknown).
7. Report bitmap-allocated sectors owned by nothing (candidate orphan data).

Never write to the source image.

---

## 10. Faults found in `src/L3fsReader.php` (fixed 2026-10-12)

All of the following were corrected in the reader, except the floppy geometry
constants, which were left alone. The reader stays strict: it throws on a broken
map or directory rather than salvaging, so it still cannot open the root of
`fsdisc.img`. The list is kept as a record of easy mistakes.

- Treats the 0xF6 pointers in sectors 0 and 1 as needing to be equal. They point
  at the two different copies of the info sector.
- No ADFS checksum handling (§2.1).
- Ends an extent list on **length** == 0 and scans slots up to 0xFA as data; the
  format ends on **start** == 0 and slot 48 (offset 250) is the chain link.
- Expects sequence numbers to count up/down along a map chain, and demands the
  head be 0 in `_getSizeFromAllocationMap`. Sequence numbers are per-block and
  arbitrary; the only rule is byte 6 == byte 255.
- Requires `"JesMap"` on successor blocks.
- Ignores BILB: sizes are rounded up to whole sectors and `getFile` returns
  trailing junk.
- Does not check the directory leading/trailing sequence pair.
- Does not know about the parent pseudo-entry at offset 17 (harmless when
  following the list, since it is unchained).
- Public read/write bits are swapped: public read is 0x01, public write 0x02.
- Insists info byte 0x1E == 1 with a message saying it should be 0; that byte is
  the logical-drive increment and is normally 1.
- Trims names with `trim()`; padding is spaces, occasionally NUL.
- Geometry constants (`TRACKS_PER_SIDE`, interleave) only make sense for floppies.

---

## 11. Observations on `fsdisc.img` (the image in this repo)

For orientation only; re-derive before relying on it.

- 19,113,984 bytes = 74,664 sectors = 306 cylinders × 244 sectors. Disc name
  `FS`, start cylinder 1, root SIN 733, one bitmap sector per cylinder,
  initialised 15 March 1986.
- Sector 0 and 1 checksums are correct; pointers 245 and 489; the two info
  sectors are identical.
- 1,928 sectors start with `JesMap`: 1,891 live (sequence bytes equal), 37
  deleted (0/1, no extents). No chained maps. One false positive in file data.
- **The root directory is damaged**: its map (733) is intact and gives extents
  (734,2) (772,1) (776,1) (818,1), size 1266 bytes = 48 slots, but **sector 734
  is all zero**, so the header and slots 0–9 are gone. Sectors 735, 772, 776 and
  818 hold slots 10 onward. This is why the PHP reader returns nothing.
- 120 directories are recognisable by content; 26 of them are referenced by no
  surviving directory entry (`PanosLib`, `Library1`, `MANAGER`, `Welcome`, …) —
  consistent with being root children whose entries were in the lost sector, to
  be confirmed via their parent pointers. A further 145 live head maps (files)
  are referenced by no surviving entry.
- 1,455 sectors are entirely zero, 1,043 of them marked allocated; without a
  bad-sector list these cannot be divided into lost and genuinely empty.

---

## 12. Open questions

1. Successor map blocks: magic absent, and BILB head vs last — needs an image
   with a file of more than 48 extents, or a closer read of `Uade12` MKRLN and
   the change-size path.
2. Password entry size on early Level 3 versions.
3. Whether any Level 3 version writes the root with a parent pseudo-entry
   (FileStore `FORMAT` does: link 0xFFFF at offset 17).
4. Multi-drive volumes (`MPSZDN` > 1): sectors beyond `MPSZNS` roll onto the
   next physical drive using the addition factor. Not examined.
5. High year bits in dates on Level 3 (as opposed to FileStore).

## 13. Sources

- stardot, *Acorn-Fileservers* — reconstructed Level 3 / FileStore source:
  https://github.com/stardot/Acorn-Fileservers
  (`Uade01`/`Uade02` manifests, `Uade0D`/`Uade0DA`/`Uade0E` directory manager,
  `Uade10`–`Uade13`/`Uade12A`/`Mbbmcm` map manager, `Form00` FileStore formatter)
- oaknut-afs (Python AFS implementation, cross-reference for layouts):
  https://pypi.org/project/oaknut/
- Rick Murray, *AFS0 disc format*: https://heyrick.eu/econet/fs/afs0.html
- Acorn Application Note 075 (Level 3 file structure; could not be fetched
  during this session):
  https://www.chrisacorns.computinghistory.org.uk/docs/Acorn/AN/075.txt
- Acorn Application Note 076 (Level 2 structure — a **different** format, do not
  mix its field offsets or attribute bits with AFS0):
  https://chrisacorns.computinghistory.org.uk/docs/Acorn/AN/076.txt
