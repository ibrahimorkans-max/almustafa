"""Indicator formats, auto-detection, writing the settings, and the camera command line."""

from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from ricemill_agent.cli import camera_from, write_indicator
from ricemill_agent.detect import analyse, detect
from ricemill_agent.drivers.camera import FfmpegCamera, parse_dshow_devices
from ricemill_agent.drivers.indicator import ReversedDigitsParser, make_parser, split_frames


class FormatTest(unittest.TestCase):
    def test_reversed_digits_frames(self) -> None:
        p = ReversedDigitsParser()
        self.assertEqual(p.parse("=0.84520"), Decimal("2548.000"))
        self.assertEqual(p.parse("08452-"), Decimal("-25480.000"))
        self.assertEqual(p.parse("=0000000"), Decimal("0.000"))
        self.assertIsNone(p.parse("=ERR"))

    def test_status_frames_ignore_other_lines(self) -> None:
        p = make_parser("st_gs")
        self.assertEqual(p.parse("ST,GS,+0025480kg"), Decimal("25480.000"))
        self.assertEqual(p.parse("US,NT,-0000020kg"), Decimal("-20.000"))
        self.assertIsNone(p.parse("PF-7E V1.2"))

    def test_unknown_format_is_refused(self) -> None:
        with self.assertRaises(ValueError):
            make_parser("pf7")

    def test_splits_a_stream_into_frames(self) -> None:
        frames, rest = split_frames(b"ST,GS,+0025480kg\r\nST,GS,+00254", False)
        self.assertEqual(frames, ["ST,GS,+0025480kg"])
        self.assertEqual(rest, b"ST,GS,+00254")
        frames, rest = split_frames(b"=0.84520=0.84520=0.8", True)
        self.assertEqual(frames, ["0.84520", "0.84520"])
        self.assertEqual(rest, b"0.8")


class DetectTest(unittest.TestCase):
    def reader(self, streams: dict[int, bytes]):
        return lambda port, baud: streams.get(baud, b"")

    def test_finds_speed_and_status_format(self) -> None:
        data = b"ST,GS,+0025480kg\r\n" * 12
        best = detect(["COM3"], self.reader({4800: data}))[0]
        self.assertEqual((best.port, best.baudrate, best.fmt), ("COM3", 4800, "st_gs"))
        self.assertIn('format = "st_gs"', best.config())
        self.assertEqual(best.weights[-1], Decimal("25480.000"))

    def test_finds_reversed_digit_indicators(self) -> None:
        best = detect(["COM4"], self.reader({9600: b"=0.84520" * 20}))[0]
        self.assertEqual((best.baudrate, best.fmt), (9600, "reversed"))
        self.assertEqual(best.weights[0], Decimal("2548.000"))

    def test_garbage_at_the_wrong_speed_scores_nothing(self) -> None:
        self.assertTrue(all(c.score == 0 for c in analyse("COM3", 2400, bytes(range(128, 256)) * 4)))
        self.assertEqual(detect(["COM3"], self.reader({})), [])

    def test_writes_the_indicator_section(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "agent.toml"
            path.write_text('[scale]\nid = "x"\n\n[indicator]\ntype = "simulated"\n\n[camera]\ntype = "none"\n', encoding="utf-8")
            write_indicator(str(path), '[indicator]\ntype = "serial"\nport = "COM3"\nbaudrate = 9600\nformat = "st_gs"')
            text = path.read_text(encoding="utf-8")
            self.assertIn('port = "COM3"', text)
            self.assertNotIn('type = "simulated"', text)
            self.assertIn('[camera]\ntype = "none"', text)


class CameraTest(unittest.TestCase):
    def test_usb_and_ip_camera_commands(self) -> None:
        usb = FfmpegCamera("usb", "USB2.0 HD UVC WebCam").command()
        self.assertEqual(usb[usb.index("-f") + 1], "dshow")
        self.assertIn("video=USB2.0 HD UVC WebCam", usb)
        self.assertEqual(usb[-1], "pipe:1")
        rtsp = FfmpegCamera("rtsp", "rtsp://admin:x@192.168.1.64:554/Streaming/Channels/101").command()
        self.assertIn("-rtsp_transport", rtsp)
        self.assertIsInstance(camera_from({"type": "usb", "device": "Cam"}), FfmpegCamera)
        self.assertIsNone(camera_from({"type": "none"}))

    def test_lists_webcams_from_ffmpeg_output(self) -> None:
        old = '[dshow @ 01] DirectShow video devices\n[dshow @ 01]  "USB2.0 HD UVC WebCam"\n[dshow @ 01]     Alternative name "@device_pnp_x"\n' \
              '[dshow @ 01] DirectShow audio devices\n[dshow @ 01]  "Microphone (Realtek)"\n'
        new = '[dshow @ 02] "Integrated Camera" (video)\n[dshow @ 02]   Alternative name "@device_pnp_y"\n[dshow @ 02] "Microphone" (audio)\n'
        self.assertEqual(parse_dshow_devices(old), ["USB2.0 HD UVC WebCam"])
        self.assertEqual(parse_dshow_devices(new), ["Integrated Camera"])


if __name__ == "__main__":
    unittest.main()
