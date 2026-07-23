.syntax unified
.cpu cortex-m4
.thumb

#ifndef TARGET_ID
#define TARGET_ID 0x00000000
#endif

.section .isr_vector, "a", %progbits
.global __vector_table
__vector_table:
    .word 0x20003F00
    .word Reset_Handler + 1

.section .text.Reset_Handler, "ax", %progbits
.align 2
.global Reset_Handler
.type Reset_Handler, %function
Reset_Handler:
    cpsid i

    /* Report block at 0x20003C00. No peripheral outputs are touched. */
    ldr r0, =0x20003C00
    ldr r1, =0x43415043      /* "CAPC" in little endian */
    str r1, [r0, #0]
    movs r1, #1
    str r1, [r0, #4]        /* report format version */
    ldr r1, =TARGET_ID
    str r1, [r0, #8]

    /* Read-only ARM core identification. */
    ldr r2, =0xE000ED00     /* SCB CPUID */
    ldr r1, [r2]
    str r1, [r0, #12]

    movs r1, #0
    str r1, [r0, #16]       /* heartbeat */
    mrs r1, msp
    str r1, [r0, #20]
    mrs r1, psp
    str r1, [r0, #24]
    mrs r1, control
    str r1, [r0, #28]

1:
    ldr r1, [r0, #16]
    adds r1, r1, #1
    str r1, [r0, #16]
    b 1b
.size Reset_Handler, .-Reset_Handler
