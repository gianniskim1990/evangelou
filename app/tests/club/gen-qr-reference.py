# Regenerates tests/club/fixtures-qr-reference.json (STAGE-05D round 2) with an INDEPENDENT encoder,
# python-qrcode 7.4.2 (lincolnloop, BSD), not the Nayuki-derived algorithm of src/customer/qrEncode.ts.
# Offline / by hand only; CI never runs it. Usage (in an isolated directory, nothing else installed):
#   pip download qrcode==7.4.2 --no-deps -d dl && pip install --no-deps --target lib dl/qrcode-7.4.2-py3-none-any.whl
#   printf 'class Writer:\n    pass\n' > lib/png.py   # qrcode imports pypng for images; no image is made here
#   python3 -I gen-qr-reference.py lib fixtures-qr-reference.json
# segno 1.6.6 was tried first and is NOT bit-exact here: when the data ends on a byte boundary it adds a
# whole 0x00 codeword before the 0xEC/0x11 pads (ISO/IEC 18004 7.4.10 adds padding bits only when not
# aligned). Both decode to the same text; python-qrcode follows the standard and matches ours exactly.
import sys, json
sys.path.insert(0, sys.argv[1])
import qrcode
from qrcode.util import QRData, MODE_8BIT_BYTE
tokens = ["evc_" + "0"*48, "evc_" + "0123456789abcdef"*3, "evc_" + "f"*48, "evc_" + "a1b2c3d4e5f6"*4]
out = {"generator": "python-qrcode 7.4.2 (lincolnloop), independent of the Nayuki-derived app encoder", "options": "version 4, error M, byte mode, border 0", "cases": []}
def matrix(t, mask):
    q = qrcode.QRCode(version=4, error_correction=qrcode.constants.ERROR_CORRECT_M, border=0, mask_pattern=mask)
    q.add_data(QRData(t.encode("ascii"), mode=MODE_8BIT_BYTE))
    q.make(fit=False)
    return q, ["".join("1" if c else "0" for c in row) for row in q.get_matrix()]
for t in tokens:
    assert len(t) == 52
    q, rows = matrix(t, None)
    out["cases"].append({"text": t, "mask": None, "rows": rows})
    for mask in range(8):
        q, rows = matrix(t, mask)
        assert len(rows) == 33
        out["cases"].append({"text": t, "mask": mask, "rows": rows})
json.dump(out, open(sys.argv[2], "w"), indent=1)
print(len(out["cases"]))
